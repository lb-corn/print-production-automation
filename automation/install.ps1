param([Parameter(Mandatory=$true)][string]$PythonExe)
. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
$PythonExe = (Resolve-Path -LiteralPath $PythonExe).Path
if ($PythonExe.StartsWith('\\')) { throw 'Python must be installed locally from python.org.' }
$Signature = Get-AuthenticodeSignature -LiteralPath $PythonExe
if ($Signature.Status -ne 'Valid' -or $Signature.SignerCertificate.Subject -notmatch 'Python Software Foundation') { throw 'Use an authentic, signed python.org Windows installer and interpreter.' }
& $PythonExe -c "import sys, struct; assert sys.version_info[:2] == (3,13) and struct.calcsize('P') == 8, 'Requires Windows x64 Python 3.13'"
Assert-NativeExit 'Python platform validation'
$Venv = Join-Path $Root '.venv'
if (!(Test-Path -LiteralPath $Venv)) { & $PythonExe -I -m venv $Venv; Assert-NativeExit 'Virtual environment creation' }
$Python = Join-Path $Venv 'Scripts\python.exe'
& $Python -I -m pip --isolated install --require-hashes --only-binary=:all: --index-url https://pypi.org/simple --disable-pip-version-check -r (Join-Path $Root 'requirements.lock')
Assert-NativeExit 'Hash-locked dependency installation'
& $Python -I -m pip check
Assert-NativeExit 'Dependency consistency check'
foreach ($Directory in @('logs','factory_print')) { New-Item -ItemType Directory -Path (Join-Path $Root $Directory) -Force | Out-Null }
if (!(Test-Path -LiteralPath (Join-Path $Root '.env'))) { Copy-Item -LiteralPath (Join-Path $Root 'config.example.env') -Destination (Join-Path $Root '.env') }
Write-Host 'Dependencies installed. Configure credentials and permissions, then run .\run-once.ps1 -CheckOnly before any live job.' -ForegroundColor Green
Write-Host 'No startup task installed and no printer action executed.'
