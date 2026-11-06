[CmdletBinding()]
param([switch]$Once)
# Connection-only monitor for an existing installation. Never calls the batch API.
. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
$Python = Join-Path $Root '.venv\Scripts\python.exe'
if (!(Test-Path -LiteralPath $Python)) { throw 'The agent Python environment is missing.' }
$Reporter = @'
import hashlib, json, os, platform, re, sys, time, uuid
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit
import requests
from dotenv import dotenv_values
root = Path(sys.argv[1])
env = dotenv_values(root / '.env')
base = (env.get('PRODUCTION_API') or '').rstrip('/')
key = (env.get('FACTORY_API_KEY') or '').strip()
url = urlsplit(base)
if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment or url.path.rstrip('/') != '/api' or len(key) < 32 or key.lower().startswith(('change', 'replace')):
    print('Configure the final HTTPS /api URL and matching API key in the local .env.')
    raise SystemExit(1)
def local_path(name, default):
    value = Path(env.get(name) or default)
    return value if value.is_absolute() else root / value
identity = root / 'agent-id'
if identity.is_symlink() or (identity.exists() and getattr(identity.lstat(), 'st_file_attributes', 0) & 0x400):
    print('Agent identity cannot be a link.'); raise SystemExit(1)
try:
    with identity.open('x', encoding='ascii') as handle: handle.write(str(uuid.uuid4()))
except FileExistsError: pass
if identity.stat().st_size > 64: raise SystemExit('Invalid agent identity file.')
agent_id = str(uuid.UUID(identity.read_text(encoding='ascii').strip()))
session = requests.Session(); session.trust_env = False
def report(stopping=False):
    source = root / 'factory_agent.py'
    version = (root / 'VERSION').read_text(encoding='utf-8-sig').strip()
    if not re.fullmatch(r'\d{1,3}\.\d{1,3}\.\d{1,3}', version): raise ValueError('Invalid installed version file')
    payload = {'agent_id':agent_id, 'agent_version':version,
        'source_sha256':hashlib.sha256(source.read_bytes()).hexdigest(), 'python_version':platform.python_version(),
        'windows_version':platform.version() if os.name == 'nt' else '0.0', 'sampled_at_ms':time.time_ns()//1000000,
        'reporter_mode':'connection_only', 'process_status':'stopping' if stopping else ('checking' if sys.argv[2] == 'once' else 'running'), 'stage':'stopped' if stopping else 'idle',
        'batch_id':None, 'workspace_exists':local_path('FACTORY_PRINT_DIR','factory_print').is_dir(),
        'hot_folder_exists':bool(env.get('EDGE_PRINT_HOT_FOLDER') and local_path('EDGE_PRINT_HOT_FOLDER','').is_dir()),
        'credentials_present':local_path('GOOGLE_SERVICE_ACCOUNT_FILE','service-account.json').is_file()}
    fields = {name: '' if value is None else ('1' if value else '0') if isinstance(value,bool) else str(value) for name,value in payload.items()}
    with session.post(base + '/factory/heartbeat', data=fields, headers={'Authorization':'Bearer '+key,'Accept':'application/json, */*;q=0.5','User-Agent':'LuckyBags-Factory-Connection/1'},
                      timeout=(5,10), allow_redirects=False, verify=True, stream=True) as response:
        if response.status_code != 200:
            if response.status_code == 406:
                print('HTTP 406: request was rejected before portal confirmation. Ask hosting support to inspect the WAF/ModSecurity audit for POST /api/factory/heartbeat at', datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S UTC'))
            else:
                print('Connection report rejected: HTTP', response.status_code, '(check deployment, key, migration and PC clock).')
            return False
        body = bytearray()
        for chunk in response.iter_content(8192):
            body.extend(chunk)
            if len(body) > 65536: raise ValueError('Unexpected response size')
        if json.loads(body).get('ok') is not True: raise ValueError('Unexpected heartbeat response')
    if not stopping: print('Connection report sent. No production worker or print action was started.')
    return True
print('Factory connection monitor only. Keep this window open; Ctrl+C stops reporting.')
try:
    while True:
        try: succeeded = report()
        except Exception:
            print('Connection report could not be sent. Check HTTPS and the local configuration.'); succeeded = False
        if sys.argv[2] == 'once': raise SystemExit(0 if succeeded else 1)
        time.sleep(30)
except KeyboardInterrupt:
    try: report(stopping=True)
    except Exception: pass
    print('Connection reporting stopped. Production processing was not changed.')
'@
& $Python -I -c $Reporter $Root $(if ($Once) {'once'} else {'watch'})
Assert-NativeExit 'Connection-only reporter'
