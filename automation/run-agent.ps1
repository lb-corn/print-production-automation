$Root=Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $Root
if (!(Test-Path ".\.venv\Scripts\python.exe")) { Write-Host "Missing .venv. Run .\install.ps1 first." -ForegroundColor Red; exit 1 }
& ".\.venv\Scripts\python.exe" ".\factory_agent.py"
