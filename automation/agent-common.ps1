Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
function Assert-AgentRoot([string]$Path) {
    $Resolved = [IO.Path]::GetFullPath($Path)
    if ($Resolved.StartsWith('\\') -or $Resolved -eq [IO.Path]::GetPathRoot($Resolved)) { throw 'Use a dedicated local installation directory, not a share or drive root.' }
    $Current = Get-Item -LiteralPath $Resolved
    while ($null -ne $Current) {
        if ($Current.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Junctions and symbolic links are not allowed in the installation path.' }
        $Current = $Current.Parent
    }
    return $Resolved
}
function Assert-NativeExit([string]$Action) {
    if ($LASTEXITCODE -ne 0) { throw "$Action failed with exit code $LASTEXITCODE" }
}
function Assert-AgentStopped([string]$Root) {
    $Task = Get-ScheduledTask -TaskName 'LuckyBagsFactoryAgent' -ErrorAction SilentlyContinue
    if ($Task -and $Task.State -eq 'Running') { throw 'Stop the Factory Agent scheduled task before updating.' }
    $Python = Join-Path $Root '.venv\Scripts\python.exe'
    $Running = Get-CimInstance Win32_Process -Filter "Name='python.exe' OR Name='pythonw.exe'" | Where-Object { $_.ExecutablePath -and $_.ExecutablePath.StartsWith((Join-Path $Root '.venv\'), [StringComparison]::OrdinalIgnoreCase) }
    if ($Running) { throw 'Close all Factory Agent processes before updating.' }
}
