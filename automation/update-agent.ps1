param([Parameter(Mandatory=$true)][string]$ZipPath,[Parameter(Mandatory=$true)][ValidatePattern('^[a-fA-F0-9]{64}$')][string]$ExpectedSha256)
. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
Assert-AgentStopped $Root
$ZipPath = (Resolve-Path -LiteralPath $ZipPath).Path
if ((Get-FileHash -LiteralPath $ZipPath -Algorithm SHA256).Hash -ne $ExpectedSha256) { throw 'UPDATE BLOCKED: release checksum mismatch.' }
# Explicit file list: an archive cannot replace secrets, runtime directories or arbitrary scripts.
$Allowed = @('factory_agent.py','requirements.txt','requirements.lock','VERSION','release.json','config.example.env','agent-common.ps1','install.ps1','protect-agent.ps1','run-agent.ps1','run-once.ps1','install-autostart.ps1','uninstall-autostart.ps1','agent-info.ps1','update-agent.ps1','README.md','SECURITY_REVIEW.md')
Add-Type -AssemblyName System.IO.Compression.FileSystem
$Archive = [IO.Compression.ZipFile]::OpenRead($ZipPath)
$Staging = Join-Path $Root ('.update-' + [guid]::NewGuid())
try {
    New-Item -ItemType Directory -Path $Staging | Out-Null
    $Seen = @{}
    foreach ($Entry in $Archive.Entries) {
        if ($Allowed -notcontains $Entry.FullName -or $Seen.ContainsKey($Entry.FullName) -or $Entry.Length -gt 5MB) { throw 'UPDATE BLOCKED: unexpected, duplicate or oversized archive entry.' }
        $Seen[$Entry.FullName] = $true
        [IO.Compression.ZipFileExtensions]::ExtractToFile($Entry,(Join-Path $Staging $Entry.FullName),$false)
    }
    if ($Seen.Count -ne $Allowed.Count) { throw 'UPDATE BLOCKED: incomplete release archive.' }
    $Manifest = Get-Content -LiteralPath (Join-Path $Staging 'release.json') -Raw | ConvertFrom-Json
    if ((Get-FileHash -LiteralPath (Join-Path $Staging 'factory_agent.py') -Algorithm SHA256).Hash.ToLower() -ne $Manifest.factory_agent_sha256) { throw 'UPDATE BLOCKED: agent manifest hash mismatch.' }
    foreach ($Name in $Allowed) {
        $Target = Join-Path $Root $Name
        if ((Test-Path -LiteralPath $Target) -and ((Get-Item -LiteralPath $Target).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'UPDATE BLOCKED: destination is a link.' }
    }
    # Keep a source-only rollback copy; never copy credentials or runtime state into backups.
    $Backup = Join-Path $Root ('backup-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
    New-Item -ItemType Directory -Path $Backup | Out-Null
    foreach ($Name in $Allowed) {
        $Target = Join-Path $Root $Name
        if (Test-Path -LiteralPath $Target) { Copy-Item -LiteralPath $Target -Destination (Join-Path $Backup $Name) }
        Copy-Item -LiteralPath (Join-Path $Staging $Name) -Destination $Target -Force
    }
    Write-Host 'Source update applied. Re-run install.ps1 for the new dependency lock, then configuration checks. Agent remains stopped.' -ForegroundColor Green
} finally {
    $Archive.Dispose()
    $ResolvedStaging = [IO.Path]::GetFullPath($Staging)
    if ($ResolvedStaging.StartsWith($Root + [IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase) -and (Test-Path -LiteralPath $ResolvedStaging)) { Remove-Item -LiteralPath $ResolvedStaging -Recurse -Force }
}
