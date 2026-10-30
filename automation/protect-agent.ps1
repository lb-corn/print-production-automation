. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
$Identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
$SystemSid = New-Object Security.Principal.SecurityIdentifier('S-1-5-18')
$AdminsSid = New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')
# Scope is restricted to the dedicated installation directory and its existing descendants.
$Targets = @((Get-Item -LiteralPath $Root)) + @(Get-ChildItem -LiteralPath $Root -Recurse -Force)
foreach ($Target in $Targets) {
    if ($Target.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Permission hardening blocked: remove symbolic links or junctions first.' }
}
foreach ($Target in $Targets) {
    if ($Target.PSIsContainer) {
        $Acl = New-Object Security.AccessControl.DirectorySecurity
        $Inheritance = [Security.AccessControl.InheritanceFlags]'ContainerInherit,ObjectInherit'
    } else {
        $Acl = New-Object Security.AccessControl.FileSecurity
        $Inheritance = [Security.AccessControl.InheritanceFlags]::None
    }
    $Acl.SetAccessRuleProtection($true,$false)
    foreach ($Sid in @($Identity,$SystemSid,$AdminsSid)) {
        $Rule = New-Object Security.AccessControl.FileSystemAccessRule($Sid,'FullControl',$Inheritance,'None','Allow')
        $Acl.AddAccessRule($Rule)
    }
    Set-Acl -LiteralPath $Target.FullName -AclObject $Acl
}
Write-Host 'Agent directory restricted to this operator account, SYSTEM and Administrators. This does not protect against malware already running as this same account or as Administrator.'
