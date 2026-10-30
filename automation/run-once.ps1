param([switch]$CheckOnly)
. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
$Python = Join-Path $Root '.venv\Scripts\python.exe'
if (!(Test-Path -LiteralPath $Python)) { throw 'Run install.ps1 first.' }
if ($CheckOnly) { & $Python -I (Join-Path $Root 'factory_agent.py') --check }
else { & $Python -I (Join-Path $Root 'factory_agent.py') --once }
Assert-NativeExit 'Factory Agent'
