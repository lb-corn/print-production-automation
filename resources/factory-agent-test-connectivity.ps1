# Safe on v1.2.2 installations too. Reads only the agent .env; never requests a batch.
. (Join-Path $PSScriptRoot 'agent-common.ps1')
$Root = Assert-AgentRoot $PSScriptRoot
$Python = Join-Path $Root '.venv\Scripts\python.exe'
if (!(Test-Path -LiteralPath $Python)) { throw 'Run install.ps1 first.' }
$Check = @'
import sys
from urllib.parse import urlsplit
import requests
from dotenv import dotenv_values
try:
    env = dotenv_values(sys.argv[1])
    base = (env.get('PRODUCTION_API') or '').rstrip('/')
    key = (env.get('FACTORY_API_KEY') or '').strip()
    url = urlsplit(base)
    if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment or url.path.rstrip('/') != '/api':
        raise ValueError('Use the final HTTPS URL ending in /api')
    if len(key) < 32 or key.lower().startswith(('change', 'replace')):
        raise ValueError('API key must be configured')
    session = requests.Session()
    session.trust_env = False
    with session.get(base + '/factory/health', headers={'Authorization': 'Bearer ' + key, 'Accept': 'application/json, */*;q=0.5','User-Agent':'LuckyBags-Factory-Health/1'}, timeout=(5, 15), allow_redirects=False, stream=True) as response:
        print('Factory health HTTP status:', response.status_code)
        if response.status_code == 404:
            print('Deploy the portal monitoring update first. The endpoint is /api/factory/health, not /api/health.')
        elif response.status_code in (401, 503):
            print('Check that FACTORY_API_KEY matches on PC and server, and clear the Laravel configuration cache after changing the server key.')
        if response.status_code != 200:
            raise SystemExit(1)
        body = bytearray()
        for chunk in response.iter_content(8192):
            body.extend(chunk)
            if len(body) > 65536:
                raise ValueError('Unexpected health response size')
        import json
        data = json.loads(body)
        if data.get('authenticated') is not True:
            raise ValueError('Unexpected health response')
        print('Factory API key authentication: PASSED')
        print('Monitoring database ready:', data.get('monitoring_ready') is True)
        print('Read-only check complete. No batch, download, handoff or print action was requested.')
except SystemExit:
    raise
except Exception:
    print('Connection check failed. Check HTTPS, the /api URL, server deployment and the local .env. No secret values or response bodies are displayed.')
    raise SystemExit(1)
'@
& $Python -I -c $Check (Join-Path $Root '.env')
Assert-NativeExit 'Read-only Factory API connectivity check'
