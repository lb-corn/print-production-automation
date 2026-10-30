param([string]$OutputDir=(Join-Path $PSScriptRoot 'dist'))
. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
$Version = (Get-Content -LiteralPath (Join-Path $Root 'VERSION') -Raw).Trim()
if ($Version -notmatch '^\d+\.\d+\.\d+$') { throw 'Invalid release version.' }
$Include = @('factory_agent.py','requirements.txt','requirements.lock','VERSION','release.json','config.example.env','agent-common.ps1','install.ps1','protect-agent.ps1','run-agent.ps1','run-once.ps1','install-autostart.ps1','uninstall-autostart.ps1','agent-info.ps1','update-agent.ps1','README.md','SECURITY_REVIEW.md')
$Manifest = @{name='LuckyBags Factory Agent';version=$Version;factory_agent_sha256=(Get-FileHash -LiteralPath (Join-Path $Root 'factory_agent.py') -Algorithm SHA256).Hash.ToLower()}
$Manifest | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $Root 'release.json') -Encoding UTF8
New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
$Zip = Join-Path $OutputDir "LuckyBags-Factory-Agent-v$Version.zip"
$Paths = $Include | ForEach-Object { Join-Path $Root $_ }
Compress-Archive -LiteralPath $Paths -DestinationPath $Zip -Force
$Hash=(Get-FileHash -LiteralPath $Zip -Algorithm SHA256).Hash.ToLower()
"$Hash  LuckyBags-Factory-Agent-v$Version.zip" | Set-Content -LiteralPath (Join-Path $OutputDir "LuckyBags-Factory-Agent-v$Version.sha256.txt")
Write-Host "Release created: $Zip"
Write-Host "SHA256: $Hash"
Write-Host 'Publish the hash through an independently trusted channel. A hash is not a publisher signature.'
