[CmdletBinding()]
param([string]$InstallDirectory='C:\LuckyBagsFactory')
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$PackageUrl='__PACKAGE_URL__'
$ExpectedSha256='__PACKAGE_SHA256__'
$ExpectedVersion='__PACKAGE_VERSION__'
function Assert-ExistingInstallation([string]$Path) {
    $Resolved=[IO.Path]::GetFullPath($Path).TrimEnd('\')
    if($Resolved.StartsWith('\\') -or $Resolved -eq [IO.Path]::GetPathRoot($Resolved)){throw 'Use the existing local agent folder, never a drive root or share.'}
    $Current=Get-Item -LiteralPath $Resolved
    if(!$Current.PSIsContainer){throw 'Existing installation directory not found.'}
    while($null -ne $Current){if($Current.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Linked installation paths are not permitted.'};$Current=$Current.Parent}
    foreach($Name in @('.env','service-account.json','factory_agent.py','.venv\Scripts\python.exe')){if(!(Test-Path -LiteralPath (Join-Path $Resolved $Name) -PathType Leaf)){throw "Existing installation is incomplete: $Name. This updater does not create a new installation."}}
    return $Resolved
}
function Assert-PackageEntries($Archive) {
    $Allowed=@('factory_agent.py','job_output.py','custom_transfer.py','requirements.txt','requirements.lock','VERSION','release.json','config.example.env','agent-common.ps1','install.ps1','protect-agent.ps1','run-agent.ps1','run-once.ps1','install-autostart.ps1','uninstall-autostart.ps1','agent-info.ps1','update-agent.ps1','README.md','SECURITY_REVIEW.md','test-connectivity.ps1','report-connection.ps1','install-portal-control.ps1','repair-background-startup.ps1')
    $Seen=@{}
    foreach($Entry in $Archive.Entries){if($Allowed -notcontains $Entry.FullName -or $Seen.ContainsKey($Entry.FullName) -or $Entry.Length -gt 5MB){throw 'Unexpected, duplicate or oversized update entry.'};$Seen[$Entry.FullName]=$true}
    if($Seen.Count -ne $Allowed.Count){throw 'Incomplete update package.'}
}
$Root=Assert-ExistingInstallation $InstallDirectory
$Identity=[Security.Principal.WindowsIdentity]::GetCurrent()
if((New-Object Security.Principal.WindowsPrincipal($Identity)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)){throw 'Open this updater as the normal printer operator, without Run as administrator.'}
if($ExpectedVersion -notmatch '^\d+\.\d+\.\d+$' -or $ExpectedSha256 -notmatch '^[a-f0-9]{64}$' -or ([uri]$PackageUrl).Scheme -ne 'https'){throw 'Download a fresh updater from Portal Admin. This template cannot run directly.'}
$Python=Join-Path $Root '.venv\Scripts\python.exe'
$DownloadDirectory=if($env:LUCKYBAGS_UPDATE_SELF){Split-Path -LiteralPath $env:LUCKYBAGS_UPDATE_SELF}else{$PSScriptRoot}
$ZipPath=Join-Path $DownloadDirectory ('LuckyBags-Factory-Agent-v'+$ExpectedVersion+'-'+$ExpectedSha256.Substring(0,8)+'.zip')
$TemporaryRoot=[IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')
$Staging=Join-Path $TemporaryRoot ('LuckyBagsUpdate-'+[guid]::NewGuid())
New-Item -ItemType Directory -Path $Staging | Out-Null
$Task=$null;$WasEnabled=$false;$WasRunning=$false;$Helper=$null;$UpdatesStarted=$false
$Release=Join-Path $Staging 'release-lock';$State=Join-Path $Staging 'maintenance.json'
$Preserved=@{}
foreach($Name in @('.env','service-account.json','agent-id','agent-id.json')){if(Test-Path -LiteralPath (Join-Path $Root $Name)){$Preserved[$Name]=(Get-FileHash -LiteralPath (Join-Path $Root $Name) -Algorithm SHA256).Hash}}
try {
    Write-Host "Updating the existing $Root installation to $ExpectedVersion. Settings and workspaces are kept."
    if(!(Test-Path -LiteralPath $ZipPath) -or (Get-FileHash -LiteralPath $ZipPath -Algorithm SHA256).Hash -ne $ExpectedSha256){
        $Download=Join-Path $Staging 'package.download'
        try{Invoke-WebRequest -UseBasicParsing -Uri $PackageUrl -OutFile $Download -MaximumRedirection 0 -TimeoutSec 180 | Out-Null}catch{throw 'Package download failed or the download link expired. Download a fresh updater from Portal Admin.'}
        if((Get-FileHash -LiteralPath $Download -Algorithm SHA256).Hash -ne $ExpectedSha256){throw 'Package checksum mismatch. Nothing was installed.'}
        Move-Item -LiteralPath $Download -Destination $ZipPath -Force
    }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $Archive=[IO.Compression.ZipFile]::OpenRead($ZipPath)
    try{
        Assert-PackageEntries $Archive
        foreach($Entry in $Archive.Entries){[IO.Compression.ZipFileExtensions]::ExtractToFile($Entry,(Join-Path $Staging $Entry.FullName),$false)}
    }finally{$Archive.Dispose()}
    $Manifest=Get-Content -LiteralPath (Join-Path $Staging 'release.json') -Raw | ConvertFrom-Json
    if($Manifest.version -ne $ExpectedVersion){throw 'Release version mismatch.'}
    foreach($Pair in @(@('factory_agent.py','factory_agent_sha256'),@('job_output.py','job_output_sha256'),@('custom_transfer.py','custom_transfer_sha256'))){if((Get-FileHash -LiteralPath (Join-Path $Staging $Pair[0]) -Algorithm SHA256).Hash.ToLower() -ne $Manifest.($Pair[1])){throw 'Release source integrity check failed.'}}
    $Pins=@'
import json,sys
from pathlib import Path
from dotenv import dotenv_values
values=dotenv_values(Path(sys.argv[1])/'.env')
required={'REQUIRED_AGENT_VERSION':sys.argv[2],'REQUIRED_AGENT_SHA256':sys.argv[3]}
print(json.dumps([key for key,value in required.items() if values.get(key) and values[key].strip().lower()!=value.lower()]))
'@
    $PinResult=& $Python -I -c $Pins $Root $ExpectedVersion $Manifest.factory_agent_sha256
    if($LASTEXITCODE -ne 0){throw 'Cannot inspect the existing version pins. No settings were changed.'}
    $PinChanges=@($PinResult | ConvertFrom-Json)
    if($PinChanges.Count){foreach($Pin in $PinChanges){$Value=if($Pin -eq 'REQUIRED_AGENT_VERSION'){$ExpectedVersion}else{$Manifest.factory_agent_sha256};Write-Host "In the existing .env, manually set $Pin=$Value" -ForegroundColor Yellow};throw 'An explicit .env version pin needs the changes listed above. Save that edit and run this updater again. No code was replaced.'}
    $Task=Get-ScheduledTask -TaskName LuckyBagsFactoryAgent -ErrorAction SilentlyContinue
    if($Task){
        $OwnTask=@($Task.Actions | Where-Object {$_.WorkingDirectory -and [IO.Path]::GetFullPath($_.WorkingDirectory).TrimEnd('\') -eq $Root})
        if(!$OwnTask.Count){throw 'The existing task points to a different installation. Nothing was stopped.'}
        $WasEnabled=[bool]$Task.Settings.Enabled;$WasRunning=$Task.State -eq 'Running'
        Disable-ScheduledTask -TaskName LuckyBagsFactoryAgent | Out-Null
    }
    $Processes=@(Get-CimInstance Win32_Process -Filter "Name='python.exe' OR Name='pythonw.exe'" | Where-Object {(($_.ExecutablePath -and $_.ExecutablePath.StartsWith((Join-Path $Root '.venv\'),[StringComparison]::OrdinalIgnoreCase)) -or ($_.CommandLine -and $_.CommandLine.IndexOf((Join-Path $Root 'factory_agent.py'),[StringComparison]::OrdinalIgnoreCase) -ge 0))})
    $Listeners=@($Processes | Where-Object {$_.CommandLine -match '(?:^|\s)--listen(?:\s|$)' -and $_.CommandLine.IndexOf((Join-Path $Root 'factory_agent.py'),[StringComparison]::OrdinalIgnoreCase) -ge 0})
    $ListenerIds=@($Listeners | ForEach-Object {[int]$_.ProcessId})
    if(@($Processes | Where-Object {$ListenerIds -notcontains $_.ProcessId}).Count){throw 'A job, transfer or another agent process is active. Let it finish, then run this updater again.'}
    # Freeze only verified listener PIDs; hold the real workspace lock and reject uncertain claims.
    $Maintenance=@'
import ctypes,hashlib,importlib.util,json,sys,time
from pathlib import Path
root,state,release=map(Path,sys.argv[1:4]);pids=json.loads(sys.argv[4]);parent=int(sys.argv[5]);handles=[];lock=None
kernel=ctypes.WinDLL('kernel32',use_last_error=True);native=ctypes.WinDLL('ntdll')
kernel.OpenProcess.restype=ctypes.c_void_p;kernel.OpenProcess.argtypes=[ctypes.c_ulong,ctypes.c_int,ctypes.c_ulong]
kernel.CloseHandle.argtypes=[ctypes.c_void_p]
native.NtSuspendProcess.argtypes=[ctypes.c_void_p];native.NtResumeProcess.argtypes=[ctypes.c_void_p]
def publish(value):
 temporary=state.with_suffix('.tmp');temporary.write_text(json.dumps(value),encoding='utf-8');temporary.replace(state)
try:
 for pid in pids:
  handle=kernel.OpenProcess(0x1800,False,int(pid))
  if not handle or native.NtSuspendProcess(handle)!=0:raise RuntimeError('listener_busy')
  handles.append(handle)
 manifest=json.loads((root/'release.json').read_text(encoding='utf-8-sig'))
 if hashlib.sha256((root/'factory_agent.py').read_bytes()).hexdigest()!=manifest.get('factory_agent_sha256'):raise RuntimeError('installed_integrity')
 spec=importlib.util.spec_from_file_location('installed_update_check',root/'factory_agent.py');agent=importlib.util.module_from_spec(spec);spec.loader.exec_module(agent)
 lock=agent.acquire_lock('.agent.lock')
 journal=agent.OUT/'portal-operation.json'
 if journal.exists():
  if journal.stat().st_size>4096:raise RuntimeError('uncertain_claim')
  operation=json.loads(journal.read_text(encoding='utf-8'))
  if operation.get('state')=='requesting':raise RuntimeError('uncertain_claim')
  if operation.get('token'):
   agent.PORTAL_MODE=True;agent.ACTIVE_OPERATION=operation
   try:agent.api('GET',f"/factory/batches/{int(operation['batch_id'])}/assigned")
   except RuntimeError as error:
    if str(error)!='Factory API returned HTTP 409':raise RuntimeError('cannot_verify_job')
   else:raise RuntimeError('active_job')
 publish({'ready':True})
 deadline=time.monotonic()+1200
 while not release.exists() and time.monotonic()<deadline:
  parent_handle=kernel.OpenProcess(0x1000,False,parent)
  if not parent_handle:break
  code=ctypes.c_ulong();kernel.GetExitCodeProcess.argtypes=[ctypes.c_void_p,ctypes.POINTER(ctypes.c_ulong)];kernel.GetExitCodeProcess(parent_handle,ctypes.byref(code));kernel.CloseHandle(parent_handle)
  if code.value!=259:break
  time.sleep(.2)
except Exception as error:
 publish({'ready':False,'reason':str(error) if str(error) in {'listener_busy','installed_integrity','uncertain_claim','cannot_verify_job','active_job'} else 'active_process'})
finally:
 if lock:lock.close()
 for handle in handles:
  native.NtResumeProcess(handle);kernel.CloseHandle(handle)
'@
    $MaintenancePath=Join-Path $Staging 'maintenance.py';[IO.File]::WriteAllText($MaintenancePath,$Maintenance)
    $PidJson=ConvertTo-Json -InputObject $ListenerIds -Compress
    $Arguments=@('-I',('"'+$MaintenancePath+'"'),('"'+$Root+'"'),('"'+$State+'"'),('"'+$Release+'"'),$PidJson,[string]$PID)
    $Helper=Start-Process -FilePath $Python -ArgumentList $Arguments -WindowStyle Hidden -PassThru
    $Deadline=(Get-Date).AddSeconds(90)
    while(!(Test-Path -LiteralPath $State) -and !$Helper.HasExited -and (Get-Date) -lt $Deadline){Start-Sleep -Milliseconds 200}
    if(!(Test-Path -LiteralPath $State)){throw 'Could not obtain an idle maintenance window. Nothing was replaced.'}
    $Ready=Get-Content -LiteralPath $State -Raw | ConvertFrom-Json
    if(!$Ready.ready){throw "Update postponed ($($Ready.reason)). Finish active work or inspect an uncertain job, then retry. No code or .env changes were made."}
    $Others=@(Get-CimInstance Win32_Process -Filter "Name='python.exe' OR Name='pythonw.exe'" | Where-Object {(($_.ExecutablePath -and $_.ExecutablePath.StartsWith((Join-Path $Root '.venv\'),[StringComparison]::OrdinalIgnoreCase)) -or ($_.CommandLine -and $_.CommandLine.IndexOf((Join-Path $Root 'factory_agent.py'),[StringComparison]::OrdinalIgnoreCase) -ge 0)) -and $_.ProcessId -ne $Helper.Id -and $ListenerIds -notcontains $_.ProcessId})
    if($Others.Count){throw 'Another agent process started. No source files were replaced.'}
    if($Task -and $WasRunning){Stop-ScheduledTask -TaskName LuckyBagsFactoryAgent}
    foreach($Listener in $Listeners){$Current=Get-CimInstance Win32_Process -Filter ('ProcessId='+$Listener.ProcessId) -ErrorAction SilentlyContinue;if($Current -and $Current.ExecutablePath -eq $Listener.ExecutablePath -and $Current.CommandLine -eq $Listener.CommandLine){Stop-Process -Id $Listener.ProcessId -ErrorAction SilentlyContinue}}
    New-Item -ItemType File -Path $Release -Force | Out-Null
    if(!$Helper.WaitForExit(10000)){throw 'Maintenance helper has not released the worker lock. Nothing was installed.'}
    foreach($Name in @('agent-common.ps1','update-agent.ps1','install-portal-control.ps1')){if((Test-Path -LiteralPath (Join-Path $Root $Name)) -and ((Get-Item -LiteralPath (Join-Path $Root $Name)).Attributes -band [IO.FileAttributes]::ReparsePoint)){throw 'Bootstrap source destination cannot be a link.'}}
    $UpdatesStarted=$true
    foreach($Name in @('agent-common.ps1','update-agent.ps1','install-portal-control.ps1')){Copy-Item -LiteralPath (Join-Path $Staging $Name) -Destination (Join-Path $Root $Name) -Force}
    & (Join-Path $Root 'install-portal-control.ps1') -ZipPath $ZipPath -ExpectedSha256 $ExpectedSha256
    foreach($Name in $Preserved.Keys){if(!(Test-Path -LiteralPath (Join-Path $Root $Name)) -or (Get-FileHash -LiteralPath (Join-Path $Root $Name) -Algorithm SHA256).Hash -ne $Preserved[$Name]){throw "Preserved settings changed unexpectedly: $Name"}}
    if((Get-Content -LiteralPath (Join-Path $Root 'VERSION') -Raw).Trim() -ne $ExpectedVersion){throw 'Installed version verification failed.'}
    Write-Host "Updated to $ExpectedVersion in $Root. Existing .env, credentials, identity and workspaces were preserved." -ForegroundColor Green
    Write-Host 'Background listener start requested. You may close this window and refresh Factory Agent status in the portal.'
}finally{
    if($Helper -and !$Helper.HasExited){New-Item -ItemType File -Path $Release -Force | Out-Null;$null=$Helper.WaitForExit(75000)}
    if(!$UpdatesStarted -and $Task -and $WasEnabled){Enable-ScheduledTask -TaskName LuckyBagsFactoryAgent | Out-Null;if($WasRunning){Start-ScheduledTask -TaskName LuckyBagsFactoryAgent}}
    $ResolvedStaging=[IO.Path]::GetFullPath($Staging)
    if([IO.Path]::GetDirectoryName($ResolvedStaging) -eq $TemporaryRoot -and [IO.Path]::GetFileName($ResolvedStaging).StartsWith('LuckyBagsUpdate-') -and (!$Helper -or $Helper.HasExited)){
        $Contents=@(Get-ChildItem -LiteralPath $ResolvedStaging -Force)
        if(!@($Contents | Where-Object {$_.PSIsContainer -or ($_.Attributes -band [IO.FileAttributes]::ReparsePoint)}).Count){foreach($File in $Contents){Remove-Item -LiteralPath $File.FullName -Force};Remove-Item -LiteralPath $ResolvedStaging -Force}
    }
}
