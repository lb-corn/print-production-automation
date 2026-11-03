[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$ZipPath,
      [Parameter(Mandatory=$true)][ValidatePattern('^[a-fA-F0-9]{64}$')][string]$ExpectedSha256)
# Run once beside the existing installation. No keys or credentials are downloaded.
. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
Assert-AgentStopped $Root
$Identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$Principal = New-Object Security.Principal.WindowsPrincipal($Identity)
if ($Principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Use the standard printer operator account, without elevation.' }
$ZipPath = (Resolve-Path -LiteralPath $ZipPath).Path
if ((Get-FileHash -LiteralPath $ZipPath -Algorithm SHA256).Hash -ne $ExpectedSha256) { throw 'Package checksum mismatch. No update or task was started.' }
$Allowed = @('factory_agent.py','requirements.txt','requirements.lock','VERSION','release.json','config.example.env','agent-common.ps1','install.ps1','protect-agent.ps1','run-agent.ps1','run-once.ps1','install-autostart.ps1','uninstall-autostart.ps1','agent-info.ps1','update-agent.ps1','README.md','SECURITY_REVIEW.md','test-connectivity.ps1','report-connection.ps1','install-portal-control.ps1','repair-background-startup.ps1')
Add-Type -AssemblyName System.IO.Compression.FileSystem
$Archive = [IO.Compression.ZipFile]::OpenRead($ZipPath)
try {
    $Seen = @{}
    foreach ($Entry in $Archive.Entries) {
        if ($Allowed -notcontains $Entry.FullName -or $Seen.ContainsKey($Entry.FullName) -or $Entry.Length -gt 5MB) { throw 'Unexpected, duplicate or oversized package entry.' }
        $Seen[$Entry.FullName] = $true
    }
    if ($Seen.Count -ne $Allowed.Count) { throw 'Incomplete portal control package.' }
    # Bootstrap the reviewed updater; older installations reject newly added release files.
    $Updater = Join-Path $Root 'update-agent.ps1'
    if ((Test-Path -LiteralPath $Updater) -and ((Get-Item -LiteralPath $Updater).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Updater cannot be a link.' }
    [IO.Compression.ZipFileExtensions]::ExtractToFile($Archive.GetEntry('update-agent.ps1'), $Updater, $true)
} finally { $Archive.Dispose() }
& (Join-Path $Root 'update-agent.ps1') -ZipPath $ZipPath -ExpectedSha256 $ExpectedSha256
$Python = Join-Path $Root '.venv\Scripts\python.exe'
$Pythonw = Join-Path $Root '.venv\Scripts\pythonw.exe'
if (!(Test-Path -LiteralPath $Pythonw -PathType Leaf)) { throw 'Existing Python virtual environment is required. Run install.ps1 first.' }
& $Python -I -m pip check
Assert-NativeExit 'Dependency check'
& $Python -I (Join-Path $Root 'factory_agent.py') --check
Assert-NativeExit 'Configuration check'
& (Join-Path $Root 'repair-background-startup.ps1')
