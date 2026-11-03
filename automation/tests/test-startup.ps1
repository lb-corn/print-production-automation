$ErrorActionPreference='Stop'
$Source=Join-Path $PSScriptRoot '..\repair-background-startup.ps1'
$Tokens=$null;$ParseErrors=$null
$Ast=[System.Management.Automation.Language.Parser]::ParseFile($Source,[ref]$Tokens,[ref]$ParseErrors)
if($ParseErrors){throw 'Startup repair does not parse.'}
$Function=$Ast.Find({param($Node) $Node -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $Node.Name -eq 'New-ListenerTaskPlan'},$true)
if(!$Function){throw 'Missing task-plan function.'}
. ([scriptblock]::Create($Function.Extent.Text))
# Mock Windows task cmdlets: tests never register tasks, launch programs or touch printers.
function New-ScheduledTaskAction {param($Execute,$Argument,$WorkingDirectory) @{Execute=$Execute;Arguments=$Argument;WorkingDirectory=$WorkingDirectory}}
function New-ScheduledTaskTrigger {param([switch]$AtLogOn,$User,[switch]$Once,$At,$RepetitionInterval) @{Logon=[bool]$AtLogOn;User=$User;Once=[bool]$Once;Interval=$RepetitionInterval}}
function New-ScheduledTaskPrincipal {param($UserId,$LogonType,$RunLevel) @{User=$UserId;Type=$LogonType;Level=$RunLevel}}
function New-ScheduledTaskSettingsSet {param($MultipleInstances,$RestartCount,$RestartInterval,$ExecutionTimeLimit,[switch]$StartWhenAvailable,[switch]$AllowStartIfOnBatteries,[switch]$DontStopIfGoingOnBatteries) @{Instances=$MultipleInstances;Limit=$ExecutionTimeLimit;Battery=[bool]$DontStopIfGoingOnBatteries}}
$Root='C:\Factory Test\Printer'
$Plan=New-ListenerTaskPlan $Root 'TEST\PrinterOperator'
if($Plan.Action.Execute -ne (Join-Path $env:SystemRoot 'System32\conhost.exe')){throw 'Must use explicit system console host.'}
if($Plan.Action.Arguments -ne '--headless "C:\Factory Test\Printer\.venv\Scripts\python.exe" -I "C:\Factory Test\Printer\factory_agent.py" --listen'){throw 'Incorrect fixed/quoted listener command.'}
if($Plan.Trigger.Count -ne 2 -or !$Plan.Trigger[0].Logon -or !$Plan.Trigger[1].Once -or $Plan.Trigger[1].Interval.TotalMinutes -ne 1){throw 'Logon and recurring recovery are required.'}
if($Plan.Principal.Level -ne 'Limited' -or $Plan.Principal.Type -ne 'Interactive'){throw 'Task must keep standard-user operator context.'}
if($Plan.Settings.Instances -ne 'IgnoreNew' -or $Plan.Settings.Limit -ne [TimeSpan]::Zero -or !$Plan.Settings.Battery){throw 'Task must prevent overlap and avoid battery/runtime termination.'}
$Text=Get-Content -LiteralPath $Source -Raw
if($Text -match '(?i)ExecutionPolicy\s+Bypass' -or $Text -match '(?i)Stop-ScheduledTask|Stop-Process'){throw 'Repair must not bypass policy or terminate print work.'}
if($Text.IndexOf('Assert-AgentStopped $Root') -gt $Text.IndexOf('Register-ScheduledTask')){throw 'Running-agent guard must precede registration.'}
Write-Host 'PASS: headless command quoting, operator context, recovery triggers, no overlap, battery/runtime settings and non-destructive repair guards.'
