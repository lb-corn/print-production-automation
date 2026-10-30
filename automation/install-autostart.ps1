. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
$Python = Join-Path $Root '.venv\Scripts\python.exe'
if (!(Test-Path -LiteralPath $Python)) { throw 'Install and test the agent first.' }
$Identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$Principal = New-Object Security.Principal.WindowsPrincipal($Identity)
if ($Principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'Register this task from a dedicated standard operator account, not an elevated Administrator session.' }
$Action = New-ScheduledTaskAction -Execute $Python -Argument ('-I "' + (Join-Path $Root 'factory_agent.py') + '"') -WorkingDirectory $Root
$Trigger = New-ScheduledTaskTrigger -AtLogOn -User $Identity.Name
$TaskPrincipal = New-ScheduledTaskPrincipal -UserId $Identity.Name -LogonType Interactive -RunLevel Limited
$Settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -StartWhenAvailable
Register-ScheduledTask -TaskName 'LuckyBagsFactoryAgent' -Action $Action -Trigger $Trigger -Principal $TaskPrincipal -Settings $Settings -Force | Out-Null
Write-Host 'Standard-user logon task installed. The operator must remain signed in. No elevation or execution-policy bypass is used.' -ForegroundColor Green
