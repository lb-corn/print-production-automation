$ErrorActionPreference='Stop'
$Source=Join-Path $PSScriptRoot '..\update-installed-agent.ps1'
$ParseErrors=$null
$Ast=[System.Management.Automation.Language.Parser]::ParseFile($Source,[ref]$null,[ref]$ParseErrors)
if($ParseErrors.Count){throw 'Updater PowerShell syntax failed.'}
foreach($Name in @('Assert-ExistingInstallation','Assert-PackageEntries')){
    $Function=$Ast.Find({param($Node) $Node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $Node.Name -eq $Name},$true)
    Invoke-Expression $Function.Extent.Text
}
$Fixture=Join-Path ([IO.Path]::GetTempPath()) ('LuckyBagsUpdaterTest-'+[guid]::NewGuid())
New-Item -ItemType Directory -Path (Join-Path $Fixture '.venv\Scripts') -Force | Out-Null
try{
    foreach($Name in @('.env','service-account.json','factory_agent.py','.venv\Scripts\python.exe')){[IO.File]::WriteAllText((Join-Path $Fixture $Name),'fake fixture only')}
    $Before=(Get-FileHash -LiteralPath (Join-Path $Fixture '.env')).Hash
    if((Assert-ExistingInstallation $Fixture) -ne $Fixture){throw 'Existing destination was not retained.'}
    $Rejected=$false;try{Assert-ExistingInstallation ([IO.Path]::GetPathRoot($Fixture))}catch{$Rejected=$true}
    if(!$Rejected){throw 'Drive root was accepted.'}
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $Zip=[IO.Compression.ZipFile]::OpenRead((Join-Path $PSScriptRoot '..\dist\LuckyBags-Factory-Agent-v1.6.1.zip'))
    try{Assert-PackageEntries $Zip}finally{$Zip.Dispose()}
    $Rejected=$false;try{Assert-PackageEntries ([pscustomobject]@{Entries=@([pscustomobject]@{FullName='.env';Length=1})})}catch{$Rejected=$true}
    if(!$Rejected){throw 'Private settings entry was accepted.'}
    if((Get-FileHash -LiteralPath (Join-Path $Fixture '.env')).Hash -ne $Before){throw 'Read-only preflight changed .env.'}
    $Text=[IO.File]::ReadAllText($Source)
    foreach($Required in @('agent.acquire_lock(''.agent.lock'')','NtSuspendProcess','NtResumeProcess','uncertain_claim','Factory API returned HTTP 409','-WindowStyle Hidden','REQUIRED_AGENT_VERSION','REQUIRED_AGENT_SHA256')){
        if(!$Text.Contains($Required)){throw "Missing update guard: $Required"}
    }
    $MaintenancePath='C:\Temp\with space\maintenance.py';$Root=$Fixture;$State='C:\Temp\state.json';$Release='C:\Temp\release';$PidJson='[]'
    $Line=($Text -split "`n" | Where-Object {$_ -match '^    \$Arguments='})
    Invoke-Expression $Line
    if($Arguments.Count -ne 7 -or $Arguments[1] -ne ('"'+$MaintenancePath+'"')){throw 'Maintenance argument quoting failed.'}
    Write-Host 'PASS: updater syntax, existing-root validation, ZIP allowlist, preserved settings, worker-lock and recovery guards.'
}finally{
    $Resolved=[IO.Path]::GetFullPath($Fixture)
    if([IO.Path]::GetFileName($Resolved).StartsWith('LuckyBagsUpdaterTest-') -and [IO.Path]::GetDirectoryName($Resolved) -eq [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')){Remove-Item -LiteralPath $Resolved -Recurse -Force}
}

# Windows PowerShell 5.1 preserves an empty JSON array as one pipeline object.
# Exercise the updater's actual normalization code, rather than a duplicate implementation.
$PinSource=[IO.File]::ReadAllText($Source)
$PinBlock=[regex]::Match($PinSource,'(?ms)    \$ParsedPins=.+?(?=    if\(\$PinChanges.Count\))').Value
if(!$PinBlock){throw 'Updater pin normalization block was not found.'}
foreach($PinCase in @(@('[]',0),@('["REQUIRED_AGENT_VERSION"]',1),@('["REQUIRED_AGENT_VERSION","REQUIRED_AGENT_SHA256"]',2))){
    $PinResult=$PinCase[0]
    Invoke-Expression $PinBlock
    if($PinChanges.Count -ne $PinCase[1]){throw 'Empty/single/multiple pin normalization failed.'}
}
Write-Host 'Version pin normalization passed for Windows PowerShell empty, single and multiple JSON results.'
