[CmdletBinding()]
param([switch]$StatusOnly)
function New-ListenerTaskPlan([string]$Root,[string]$UserName) {
    $Python = Join-Path $Root ".venv\Scripts\python.exe"
    $ConsoleHost = Join-Path $env:SystemRoot "System32\conhost.exe"
$Arguments = '--headless "' + $Python + '" -I "' + (Join-Path $Root 'factory_agent.py') + '" --listen'
$Action = New-ScheduledTaskAction -Execute $ConsoleHost -Argument $Arguments -WorkingDirectory $Root
$Logon = New-ScheduledTaskTrigger -AtLogOn -User $UserName
# Recheck once a minute even after a clean/unexpected exit. IgnoreNew prevents overlap.
# This starts only the listener; phase locks and journals still prevent replaying print jobs.
$Recovery = New-ScheduledTaskTrigger -Once -At ((Get-Date).AddMinutes(1)) -RepetitionInterval (New-TimeSpan -Minutes 1)
$TaskPrincipal = New-ScheduledTaskPrincipal -UserId $UserName -LogonType Interactive -RunLevel Limited
$Settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    return @{Action=$Action;Trigger=@($Logon,$Recovery);Principal=$TaskPrincipal;Settings=$Settings}
}
. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
if ($StatusOnly) {
    $Task = Get-ScheduledTask -TaskName LuckyBagsFactoryAgent -ErrorAction Stop
    $Task | Select-Object TaskName,State
    $Task.Actions | Format-List Execute,Arguments,WorkingDirectory
    Get-ScheduledTaskInfo -TaskName LuckyBagsFactoryAgent | Select-Object LastRunTime,LastTaskResult,NextRunTime
    return
}
# Never stop a production worker or replace a live installation as part of repair.
Assert-AgentStopped $Root
$Identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$Principal = New-Object Security.Principal.WindowsPrincipal($Identity)
if ($Principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Run as the normal printer operator, without elevation.' }
$Python = Join-Path $Root '.venv\Scripts\python.exe'
$ConsoleHost = Join-Path $env:SystemRoot 'System32\conhost.exe'
if (!(Test-Path -LiteralPath $Python -PathType Leaf) -or !(Test-Path -LiteralPath $ConsoleHost -PathType Leaf)) { throw 'The existing Python environment and Windows console host are required.' }
& $Python -I (Join-Path $Root 'factory_agent.py') --check
Assert-NativeExit 'Configuration check'
# Explicit headless console host prevents Windows Terminal from opening/owning the listener.
# No PowerShell wrapper, shell expansion, API key argument or arbitrary remote command.
$Plan = New-ListenerTaskPlan $Root $Identity.Name
Register-ScheduledTask -TaskName LuckyBagsFactoryAgent -Action $Plan.Action -Trigger $Plan.Trigger -Principal $Plan.Principal -Settings $Plan.Settings -Force | Out-Null
Start-ScheduledTask -TaskName LuckyBagsFactoryAgent
Write-Host 'Headless listener task registered and start requested. No print job was requested by this installer.' -ForegroundColor Green
Write-Host 'Keep portal jobs paused until connection remains fresh for five minutes after closing all terminal windows. Also verify after operator sign-out/sign-in. If Offline, use -StatusOnly and inspect logs; registration alone is not proof of connection.'
