$ErrorActionPreference="Stop"
$Root=Split-Path -Parent $MyInvocation.MyCommand.Path
$Task="LuckyBagsFactoryAgent"; $Script=Join-Path $Root "run-agent.ps1"
if (!(Test-Path $Script)) { Write-Host "Missing run-agent.ps1" -ForegroundColor Red; exit 1 }
if (!(Test-Path (Join-Path $Root ".env"))) { Write-Host "Missing .env. Run install.ps1 and configure the agent first." -ForegroundColor Red; exit 1 }
Write-Host "Running preflight before installing startup task..."
& (Join-Path $Root "preflight.ps1")
$Action=New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$Script`""
$Trigger=New-ScheduledTaskTrigger -AtStartup
$Settings=New-ScheduledTaskSettingsSet -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable
Register-ScheduledTask -TaskName $Task -Action $Action -Trigger $Trigger -Settings $Settings -RunLevel Highest -Force | Out-Null
Write-Host "Installed startup task: $Task" -ForegroundColor Green
Write-Host "Verify the task runs under an account with access to Google credentials, FACTORY_PRINT_DIR, and the Edge Print hot folder." -ForegroundColor Yellow
