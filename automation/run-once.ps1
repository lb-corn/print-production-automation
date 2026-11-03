[CmdletBinding()]
[CmdletBinding()]
param([switch]$CheckOnly,[switch]$Health,[switch]$Diagnose)
. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
$Python = Join-Path $Root '.venv\Scripts\python.exe'
if (!(Test-Path -LiteralPath $Python)) { throw 'Run install.ps1 first.' }
if (([int]$CheckOnly + [int]$Health + [int]$Diagnose) -gt 1) { throw 'Choose only one of CheckOnly, Health or Diagnose.' }
if ($Health) { & $Python -I (Join-Path $Root 'factory_agent.py') --health }
elseif ($Diagnose) { & $Python -I (Join-Path $Root 'factory_agent.py') --diagnose }
elseif ($CheckOnly) { & $Python -I (Join-Path $Root 'factory_agent.py') --check }
else { & $Python -I (Join-Path $Root 'factory_agent.py') --once }
Assert-NativeExit 'Factory Agent'
