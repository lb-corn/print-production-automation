. (Join-Path $PSScriptRoot '..\agent-common.ps1')
Add-Type -AssemblyName System.IO.Compression.FileSystem
$Source = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Fixture = Join-Path ([IO.Path]::GetTempPath()) ('lb-release-test-' + [guid]::NewGuid())
New-Item -ItemType Directory -Path $Fixture | Out-Null
function Check([bool]$Condition,[string]$Message) { if (!$Condition) { throw $Message } }
function Entries([string]$Path) {
    $Zip = [IO.Compression.ZipFile]::OpenRead($Path)
    try { return @($Zip.Entries | ForEach-Object { $_.FullName }) } finally { $Zip.Dispose() }
}
function Must-Fail([scriptblock]$Action) {
    $Failed = $false
    try { & $Action | Out-Null } catch { $Failed = $true }
    Check $Failed 'Expected package validation to fail.'
}
try {
    Get-ChildItem -LiteralPath $Source -File | Where-Object { $_.Name -notin @('.env','service-account.json') } | Copy-Item -Destination $Fixture
    Set-Content -LiteralPath (Join-Path $Fixture 'service-account.json') -Value '{"type":"service_account","client_email":"test@example.invalid","private_key":"TEST FIXTURE ONLY"}'
    & (Join-Path $Fixture 'make-release.ps1')
    $Version = (Get-Content (Join-Path $Fixture 'VERSION') -Raw).Trim()
    $Private = Join-Path $Fixture "dist\LuckyBags-Factory-Agent-v$Version-PrivateInstall.zip"
    $Names = Entries $Private
    Check ($Names.Count -eq 23 -and $Names -contains '.env' -and $Names -contains 'service-account.json' -and $Names -contains 'test-connectivity.ps1') 'Private bundle must contain exactly 23 approved entries including both secrets and the health check.'
    $EnvPath = Join-Path $Fixture '.env'
    $Before = (Get-FileHash -LiteralPath $EnvPath).Hash
    (Get-Item -LiteralPath $EnvPath -Force).Attributes = [IO.FileAttributes]::Hidden
    & (Join-Path $Fixture 'make-release.ps1')
    Check ((Entries $Private) -contains '.env') 'Hidden .env was omitted.'
    Check ((Get-FileHash -LiteralPath $EnvPath).Hash -eq $Before) 'Existing .env was changed.'
    $Checksum = (Get-Content -LiteralPath ($Private -replace '\.zip$','.sha256.txt') -Raw).Split(' ')[0]
    Check ((Get-FileHash -LiteralPath $Private).Hash -eq $Checksum) 'Checksum mismatch.'
    Must-Fail { & (Join-Path $Fixture 'make-release.ps1') -OutputDir (Join-Path $Fixture 'public') }
    Set-Content -LiteralPath (Join-Path $Fixture 'service-account.json') -Value 'not-json'
    Must-Fail { & (Join-Path $Fixture 'make-release.ps1') }
    Remove-Item -LiteralPath (Join-Path $Fixture 'service-account.json')
    Must-Fail { & (Join-Path $Fixture 'make-release.ps1') }
    & (Join-Path $Fixture 'make-release.ps1') -PublicRelease
    $Public = Join-Path $Fixture "dist\LuckyBags-Factory-Agent-v$Version.zip"
    $Names = Entries $Public
    Check ($Names.Count -eq 21 -and $Names -notcontains '.env' -and $Names -notcontains 'service-account.json' -and $Names -contains 'test-connectivity.ps1') 'Public update exposed private files or omitted the health check.'
    Write-Host 'Release packaging checks passed (private/hidden files, public exclusions, checksum, preserved settings and invalid inputs).'
} finally {
    $Resolved = [IO.Path]::GetFullPath($Fixture)
    $TempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    if ($Resolved.StartsWith($TempRoot,[StringComparison]::OrdinalIgnoreCase) -and (Split-Path $Resolved -Leaf) -like 'lb-release-test-*') { Remove-Item -LiteralPath $Resolved -Recurse -Force }
}
