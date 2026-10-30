. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
& (Join-Path $Root '.venv\Scripts\python.exe') -I (Join-Path $Root 'factory_agent.py')
Assert-NativeExit 'Factory Agent'
