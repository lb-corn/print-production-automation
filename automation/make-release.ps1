param([string]$OutputDir=(Join-Path $PSScriptRoot 'dist'),[switch]$PublicRelease)
. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
$Version = (Get-Content -LiteralPath (Join-Path $Root 'VERSION') -Raw).Trim()
if ($Version -notmatch '^\d+\.\d+\.\d+$') { throw 'Invalid release version.' }
$Include = @('factory_agent.py','job_output.py','requirements.txt','requirements.lock','VERSION','release.json','config.example.env','agent-common.ps1','install.ps1','protect-agent.ps1','run-agent.ps1','run-once.ps1','install-autostart.ps1','uninstall-autostart.ps1','agent-info.ps1','update-agent.ps1','README.md','SECURITY_REVIEW.md')
$Suffix = ''
$Include += @('test-connectivity.ps1','report-connection.ps1','install-portal-control.ps1','repair-background-startup.ps1')
if (!$PublicRelease) {
    # Private installation bundles are local-only. Never include secrets in public updates.
    $PrivateDist = [IO.Path]::GetFullPath((Join-Path $Root 'dist'))
    $OutputDir = [IO.Path]::GetFullPath($OutputDir)
    if ($OutputDir -ne $PrivateDist -and !$OutputDir.StartsWith($PrivateDist + [IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)) { throw 'Private bundles must stay inside automation/dist (excluded from Git).' }
    $EnvFile = Join-Path $Root '.env'
    $CredentialFile = Join-Path $Root 'service-account.json'
    if (!(Test-Path -LiteralPath $CredentialFile -PathType Leaf)) { throw 'Place your factory service-account.json inside automation first. It will stay excluded from Git.' }
    if (!(Test-Path -LiteralPath $EnvFile)) { Copy-Item -LiteralPath (Join-Path $Root 'config.example.env') -Destination $EnvFile }
    foreach ($PrivateFile in @($EnvFile,$CredentialFile)) {
        if ((Get-Item -LiteralPath $PrivateFile -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Private bundle inputs cannot be links.' }
        if ((Get-Item -LiteralPath $PrivateFile -Force).Length -gt 1MB) { throw 'Private bundle input exceeds size limit.' }
    }
    try { $Credential = Get-Content -LiteralPath $CredentialFile -Raw | ConvertFrom-Json } catch { throw 'Invalid service-account JSON (contents withheld).' }
    if ($Credential.type -ne 'service_account' -or !$Credential.client_email -or !$Credential.private_key) { throw 'Expected a Google service-account key JSON (contents withheld).' }
    # A developer absolute path would not work after extracting on the client PC.
    $EnvText = Get-Content -LiteralPath $EnvFile -Raw
    if ($EnvText -notmatch '(?m)^GOOGLE_SERVICE_ACCOUNT_FILE=service-account\.json\s*$') { throw 'Set GOOGLE_SERVICE_ACCOUNT_FILE=service-account.json in automation/.env before packaging.' }
    $Include += @('.env','service-account.json')
    $Suffix = '-PrivateInstall'
}
$OutputModuleHash=(Get-FileHash -LiteralPath (Join-Path $Root 'job_output.py') -Algorithm SHA256).Hash.ToLower()
$Manifest = @{job_output_sha256=$OutputModuleHash;name='LuckyBags Factory Agent';version=$Version;factory_agent_sha256=(Get-FileHash -LiteralPath (Join-Path $Root 'factory_agent.py') -Algorithm SHA256).Hash.ToLower()}
$Manifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $Root 'release.json') -Encoding UTF8
$Ancestor = [IO.Path]::GetFullPath($OutputDir)
while (!(Test-Path -LiteralPath $Ancestor)) { $Ancestor = Split-Path -Parent $Ancestor }
Assert-AgentRoot $Ancestor | Out-Null
New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
Assert-AgentRoot $OutputDir | Out-Null
$ArchiveName = "LuckyBags-Factory-Agent-v$Version$Suffix"
$Zip = Join-Path $OutputDir "$ArchiveName.zip"
$Paths = $Include | ForEach-Object { Join-Path $Root $_ }
# Compress-Archive can omit hidden files. Explicit ZIP entries reliably include .env.
Add-Type -AssemblyName System.IO.Compression.FileSystem
$TemporaryZip = Join-Path $OutputDir ('.building-' + [guid]::NewGuid() + '.zip')
$Archive = [IO.Compression.ZipFile]::Open($TemporaryZip,[IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($Path in $Paths) { [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($Archive,$Path,[IO.Path]::GetFileName($Path)) | Out-Null }
} catch {
    $Archive.Dispose()
    # This exact temporary file was created above inside the checked output directory.
    Remove-Item -LiteralPath $TemporaryZip -Force
    throw
} finally { $Archive.Dispose() }
Move-Item -LiteralPath $TemporaryZip -Destination $Zip -Force
$Hash=(Get-FileHash -LiteralPath $Zip -Algorithm SHA256).Hash.ToLower()
"$Hash  $ArchiveName.zip" | Set-Content -LiteralPath (Join-Path $OutputDir "$ArchiveName.sha256.txt")
Write-Host "Release created: $Zip"
Write-Host "SHA256: $Hash"
Write-Host 'Publish the hash through an independently trusted channel. A hash is not a publisher signature.'
if (!$PublicRelease) { Write-Host 'PRIVATE INSTALL: contains .env and a Google private key. Transfer only to the trusted Print PC. Never publish, commit or use with update-agent.ps1. Edit .env on that PC: API key and actual Epson hot folder.' -ForegroundColor Yellow }
