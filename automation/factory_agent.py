from __future__ import annotations

import hashlib
import json
import logging
from logging.handlers import RotatingFileHandler
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import time
import threading
import uuid
import platform
from urllib.parse import urlsplit

import requests
from dotenv import load_dotenv

HERE = Path(__file__).resolve().parent
load_dotenv(HERE / '.env', override=False)
VERSION = (HERE / 'VERSION').read_text(encoding='utf-8').strip()
BASE = os.getenv('PRODUCTION_API', '').rstrip('/')
KEY = os.getenv('FACTORY_API_KEY', '').strip()
def installation_path(value):
    path = Path(value)
    return path if path.is_absolute() else HERE / path


CREDS = installation_path(os.getenv('GOOGLE_SERVICE_ACCOUNT_FILE', 'service-account.json'))
OUT = installation_path(os.getenv('FACTORY_PRINT_DIR', 'factory_print'))
HOT = os.getenv('EDGE_PRINT_HOT_FOLDER', '').strip()
POLL = max(10, int(os.getenv('POLL_SECONDS', '30')))
MAX_FILE_BYTES = int(os.getenv('MAX_ARTWORK_MB', '100')) * 1024 * 1024
SCAN = os.getenv('REQUIRE_DEFENDER_SCAN', 'true').lower() == 'true'
ALLOW_LOCAL_HTTP = os.getenv('ALLOW_LOCAL_HTTP', 'false').lower() == 'true'
REQ_SHA = os.getenv('REQUIRED_AGENT_SHA256', '').lower().strip()
REQ_VER = os.getenv('REQUIRED_AGENT_VERSION', '').strip()
MIME_TYPES = {'.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png',
              '.tif': 'image/tiff', '.tiff': 'image/tiff', '.pdf': 'application/pdf'}
STOP = False
log = logging.getLogger('factory-agent')
SESSION = requests.Session()
# Never obtain credentials/proxies from .netrc or ambient proxy environment settings.
SESSION.trust_env = False
MONITOR_SESSION = requests.Session()
MONITOR_SESSION.trust_env = False
MONITOR_STOP = threading.Event()
MONITOR_LOCK = threading.Lock()
MONITOR_STATE = {'process_status': 'running', 'stage': 'idle', 'batch_id': None}


def monitor_request(method, path, payload=None):
    """Separate, bounded outbound connection: never calls a batch endpoint or follows redirects."""
    url = urlsplit(BASE)
    if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment or url.path.rstrip('/') != '/api':
        raise ValueError('Monitoring requires a final HTTPS /api URL')
    if len(KEY) < 32 or KEY.lower().startswith(('change', 'replace')):
        raise ValueError('Factory API key is not configured')
    if path not in {'/factory/heartbeat', '/factory/health'}:
        raise ValueError('Unsupported monitoring endpoint')
    with MONITOR_SESSION.request(method, BASE + path, json=payload,
                                 headers={'Authorization': f'Bearer {KEY}', 'Accept': 'application/json'},
                                 timeout=(5, 10), allow_redirects=False, verify=True, stream=True) as response:
        if response.status_code != 200:
            raise RuntimeError(f'Monitoring HTTP {response.status_code}')
        data = bytearray()
        for chunk in response.iter_content(8192):
            data.extend(chunk)
            if len(data) > 65536:
                raise ValueError('Monitoring response exceeds size limit')
        return json.loads(data)


def stable_agent_id():
    path = no_reparse(HERE / 'agent-id')
    try:
        with path.open('x', encoding='ascii') as handle:
            handle.write(str(uuid.uuid4()))
    except FileExistsError:
        pass
    if path.stat().st_size > 64:
        raise ValueError('Invalid agent identifier file')
    return str(uuid.UUID(path.read_text(encoding='ascii').strip()))


def monitor_state(**values):
    with MONITOR_LOCK:
        MONITOR_STATE.update(values)


def record_operation(kind, error_code=None):
    entry = {'kind': kind, 'at_ms': time.time_ns() // 1000000}
    if error_code:
        entry['error_code'] = error_code
    monitor_state(**{'last_failure' if error_code else 'last_success': entry})


def heartbeat_payload():
    with MONITOR_LOCK:
        data = json.loads(json.dumps(MONITOR_STATE))
    # No machine/user names, filenames, paths, keys, tokens, exceptions or artwork are sent.
    return {**data, 'agent_id': stable_agent_id(), 'agent_version': VERSION,
            'source_sha256': digest(HERE / 'factory_agent.py'), 'python_version': platform.python_version(),
            'windows_version': platform.version() if os.name == 'nt' else '0.0',
            'sampled_at_ms': time.time_ns() // 1000000, 'workspace_exists': OUT.is_dir(),
            'hot_folder_exists': bool(HOT and Path(HOT).is_dir()), 'credentials_present': CREDS.is_file()}


def send_heartbeat():
    return monitor_request('POST', '/factory/heartbeat', heartbeat_payload())


def heartbeat_loop():
    while not MONITOR_STOP.is_set():
        try:
            send_heartbeat()
        except Exception:
            # Neither raw exceptions nor remote response bodies can leak credentials into logs.
            log.warning('Monitoring unavailable; batch processing was not retried by monitoring')
        MONITOR_STOP.wait(30)


def finish_monitor(monitor):
    MONITOR_STOP.set()
    monitor_state(process_status='stopping')
    monitor.join(timeout=1)
    if not monitor.is_alive():
        try:
            send_heartbeat()
        except Exception:
            pass


def directory_probe(path):
    """Create/remove only an empty directory we own. Never publish a file into the RIP."""
    root = no_reparse(path)
    if not root.is_dir():
        return False
    probe = contained(root, '.lb-directory-check-' + uuid.uuid4().hex)
    probe.mkdir()
    probe.rmdir()
    return True


def explicit_diagnostics():
    result = {'checked_at_ms': time.time_ns() // 1000000, 'workspace_writable': False,
              'hot_folder_writable': None, 'defender_enabled': None, 'defender_realtime': None,
              'defender_scan': None, 'drive_connected': None, 'error_code': None}
    configuration_valid = False
    try:
        integrity()
        configuration_valid = True
        OUT.mkdir(parents=True, exist_ok=True)
        result['workspace_writable'] = directory_probe(OUT)
        if HOT:
            result['hot_folder_writable'] = directory_probe(Path(HOT))
    except Exception:
        result['error_code'] = 'configuration'
    if configuration_valid:
        try:
            drive().files().list(pageSize=1, fields='files(id)', q='trashed = false').execute()
            result['drive_connected'] = True
        except Exception:
            result['drive_connected'] = False
            result['error_code'] = result['error_code'] or 'drive_unreachable'
    if os.name == 'nt' and result['workspace_writable']:
        try:
            powershell = Path(os.environ.get('SystemRoot', r'C:\Windows')) / 'System32/WindowsPowerShell/v1.0/powershell.exe'
            check = subprocess.run([str(powershell), '-NoProfile', '-NonInteractive', '-Command',
                                    'Get-MpComputerStatus | Select-Object AntivirusEnabled,RealTimeProtectionEnabled | ConvertTo-Json -Compress'],
                                   shell=False, capture_output=True, text=True, timeout=20)
            if check.returncode != 0:
                raise RuntimeError('Defender status unavailable')
            status_data = json.loads(check.stdout)
            result['defender_enabled'] = status_data.get('AntivirusEnabled') is True
            result['defender_realtime'] = status_data.get('RealTimeProtectionEnabled') is True
            # A benign local test proves scanner execution, not the safety of future artwork.
            probe = contained(OUT, '.lb-defender-check-' + uuid.uuid4().hex + '.txt')
            try:
                with probe.open('x', encoding='ascii') as handle:
                    handle.write('Lucky Bags diagnostic: no artwork or print commands.')
                scan_file(probe)
                result['defender_scan'] = True
            finally:
                if probe.is_file():
                    no_reparse(probe).unlink()
        except Exception:
            result['defender_scan'] = False
            result['error_code'] = result['error_code'] or 'defender_unavailable'
    if result['defender_enabled'] is False or result['defender_realtime'] is False:
        result['error_code'] = result['error_code'] or 'defender_unavailable'
    if HOT and result['hot_folder_writable'] is False:
        result['error_code'] = result['error_code'] or 'handoff_unavailable'
    monitor_state(diagnostics=result)
    record_operation('diagnostic', result['error_code'])
    return result


def digest(path, algorithm='sha256'):
    h = hashlib.new(algorithm)
    with open(path, 'rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()


def no_reparse(path):
    """Reject symlinks/junctions at every existing component, including the root."""
    path = Path(os.path.abspath(path))
    for component in [path, *path.parents]:
        if component.is_symlink():
            raise ValueError('Symbolic links are not allowed in agent paths')
        if component.exists() and getattr(component.lstat(), 'st_file_attributes', 0) & 0x400:
            raise ValueError('Windows reparse points are not allowed in agent paths')
    return path


def contained(root, *parts):
    root = no_reparse(root).resolve()
    target = no_reparse(root.joinpath(*parts)).resolve()
    if target == root or not target.is_relative_to(root):
        raise ValueError('Path escapes the approved directory')
    return target


def safe(value):
    text = re.sub(r'[^A-Za-z0-9._-]+', '_', str(value or ''))[:100].strip(' ._') or 'artwork'
    if text.split('.')[0].upper() in {'CON', 'PRN', 'AUX', 'NUL', *(f'COM{i}' for i in range(1, 10)), *(f'LPT{i}' for i in range(1, 10))}:
        text = '_' + text
    return text


def api(method, path, **kwargs):
    # No redirects: a remote service cannot redirect the shared API key elsewhere.
    with SESSION.request(method, BASE + path, headers={'Authorization': f'Bearer {KEY}', 'Accept': 'application/json'},
                         timeout=(10, 60), allow_redirects=False, stream=True, **kwargs) as response:
        if response.status_code in range(300, 400):
            raise RuntimeError('API redirect blocked; configure the final HTTPS API URL')
        if not response.ok:
            raise RuntimeError(f'Factory API returned HTTP {response.status_code}')
        body = bytearray()
        for chunk in response.iter_content(65536):
            body.extend(chunk)
            if len(body) > 4 * 1024 * 1024:
                raise RuntimeError('Factory API response exceeds size limit')
        return json.loads(body) if body else {}


def status(batch_id, state):
    if not isinstance(batch_id, int) or batch_id <= 0:
        raise ValueError('Invalid batch database ID')
    result = api('POST', f'/factory/batches/{batch_id}/status', json={'status': state})
    monitor_state(batch_id=batch_id, stage={'CLAIMED': 'validating', 'DOWNLOADING': 'downloading', 'PREPARED': 'prepared', 'SENT_TO_RIP': 'sent_to_rip', 'FAILED': 'error'}[state])
    return result


def integrity():
    if not SCAN:
        raise ValueError('Defender scanning must remain enabled')
    url = urlsplit(BASE)
    if not url.hostname or url.username or url.password or url.query or url.fragment or url.path.rstrip('/') != '/api':
        raise ValueError('PRODUCTION_API must be a final HTTPS origin followed by /api')
    if url.scheme != 'https' and not (ALLOW_LOCAL_HTTP and url.scheme == 'http' and url.hostname in {'127.0.0.1', 'localhost', '::1'}):
        raise ValueError('HTTPS is required; local HTTP requires explicit development opt-in')
    if len(KEY) < 32 or KEY.lower().startswith(('change', 'replace')):
        raise ValueError('Configure a unique random FACTORY_API_KEY of at least 32 characters')
    if not CREDS.is_file():
        raise ValueError('Google read-only service-account credentials are missing')
    no_reparse(CREDS)
    if not OUT.is_absolute() or str(OUT).startswith(('\\\\', '//')) or OUT.resolve() in {HERE, OUT.resolve().parent} or HERE.is_relative_to(OUT.resolve()):
        raise ValueError('FACTORY_PRINT_DIR must be a dedicated local absolute directory')
    no_reparse(OUT)
    if HOT:
        hot = Path(HOT)
        if not hot.is_absolute() or str(hot).startswith(('\\\\', '//')):
            raise ValueError('Hot folder must be a dedicated local absolute directory')
        hot = no_reparse(hot).resolve()
        if hot == HERE or hot == hot.parent or HERE.is_relative_to(hot) or hot.is_relative_to(OUT.resolve()) or OUT.resolve().is_relative_to(hot):
            raise ValueError('Hot folder must not overlap the artwork workspace or agent code')
    if not 1 <= MAX_FILE_BYTES <= 500 * 1024 * 1024:
        raise ValueError('MAX_ARTWORK_MB must be between 1 and 500')
    if REQ_VER and VERSION != REQ_VER:
        raise ValueError('Installed agent version does not match required version')
    if REQ_SHA and digest(HERE / 'factory_agent.py') != REQ_SHA:
        raise ValueError('Agent integrity check failed')


def drive():
    import httplib2
    from google_auth_httplib2 import AuthorizedHttp
    from google.oauth2 import service_account
    from googleapiclient.discovery import build
    credentials = service_account.Credentials.from_service_account_file(str(CREDS), scopes=['https://www.googleapis.com/auth/drive.readonly'])
    return build('drive', 'v3', http=AuthorizedHttp(credentials, http=httplib2.Http(timeout=60)), cache_discovery=False)


def check_magic(path):
    with open(path, 'rb') as handle:
        header = handle.read(16)
    ext = path.suffix.lower()
    valid = {'.jpg': header.startswith(b'\xff\xd8\xff'), '.jpeg': header.startswith(b'\xff\xd8\xff'),
             '.png': header.startswith(b'\x89PNG\r\n\x1a\n'), '.pdf': header.startswith(b'%PDF-'),
             '.tif': header.startswith((b'II*\x00', b'MM\x00*', b'II+\x00', b'MM\x00+')),
             '.tiff': header.startswith((b'II*\x00', b'MM\x00*', b'II+\x00', b'MM\x00+'))}
    if not valid.get(ext, False):
        raise ValueError('Artwork file signature does not match an allowed image/PDF format')


def scan_file(path):
    if not SCAN:
        raise RuntimeError('Defender scanning is required for client deployment')
    defender = Path(os.environ.get('ProgramFiles', r'C:\Program Files')) / 'Windows Defender' / 'MpCmdRun.exe'
    if not defender.is_file():
        raise RuntimeError('Microsoft Defender scanner unavailable; handoff blocked')
    result = subprocess.run([str(defender), '-Scan', '-ScanType', '3', '-File', str(path), '-DisableRemediation'],
                            shell=False, capture_output=True, timeout=180)
    if result.returncode != 0:
        raise RuntimeError('Defender scan did not report clean artwork; handoff blocked')


def validate_batch(batch):
    if not isinstance(batch, dict) or not isinstance(batch.get('id'), int) or batch['id'] <= 0:
        raise ValueError('Invalid batch identifier')
    if not re.fullmatch(r'[A-Za-z0-9_-]{1,80}', str(batch.get('batch_id', ''))):
        raise ValueError('Invalid batch name')
    payload = batch.get('payload', {})
    orders = payload.get('orders', [])
    if payload.get('batch_id') != batch['batch_id'] or not 1 <= len(orders) <= 500:
        raise ValueError('Invalid or empty batch payload')
    files = []
    for order in orders:
        if not isinstance(order.get('quantity'), int) or not 1 <= order['quantity'] <= 10000:
            raise ValueError('Invalid order quantity')
        artworks = order.get('artworks', [])
        if not 1 <= len(artworks) <= 2:
            raise ValueError('Invalid artwork count')
        for artwork in artworks:
            ext = Path(str(artwork.get('filename', ''))).suffix.lower()
            if ext not in MIME_TYPES or not re.fullmatch(r'[A-Za-z0-9_-]{5,200}', str(artwork.get('drive_file_id', ''))):
                raise ValueError('Only approved JPG, PNG, TIFF or PDF Drive originals are accepted')
            identity = hashlib.sha256(str(order.get('line_item_id', '')).encode()).hexdigest()[:12]
            name = safe(f"{order.get('order', '')}_{identity}_{artwork.get('side', '')}") + ext
            material = safe(artwork.get('material'))
            files.append({'relative': str(Path(material) / name), 'drive_file_id': artwork['drive_file_id'],
                          'mime': MIME_TYPES[ext], 'quantity': order['quantity'], 'side': artwork.get('side'),
                          'expected_md5': artwork.get('md5_checksum'), 'expected_version': artwork.get('drive_version')})
    if len({file['relative'] for file in files}) != len(files):
        raise ValueError('Duplicate artwork destinations')
    if payload.get('order_count') != len(orders) or payload.get('artwork_file_count') != len(files):
        raise ValueError('Batch counts do not match the locked payload')
    return files


def payload_hash(batch):
    return hashlib.sha256(json.dumps(batch['payload'], sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def batch_folder(batch):
    return contained(OUT, batch['batch_id'])


def write_json(path, data):
    no_reparse(path)
    temporary = path.with_name(path.name + '.tmp')
    no_reparse(temporary)
    with open(temporary, 'x', encoding='utf-8') as handle:
        json.dump(data, handle, indent=2)
        handle.flush()
        os.fsync(handle.fileno())
    temporary.replace(path)


def download(service, file, destination):
    from googleapiclient.http import MediaIoBaseDownload
    metadata = service.files().get(fileId=file['drive_file_id'], fields='id,mimeType,size,md5Checksum,version').execute()
    size = int(metadata.get('size', 0))
    if metadata.get('mimeType') != file['mime'] or not 0 < size <= MAX_FILE_BYTES or not metadata.get('md5Checksum'):
        raise ValueError('Drive original has unexpected type, size or missing checksum')
    if file.get('expected_md5') and metadata['md5Checksum'] != file['expected_md5']:
        raise ValueError('Drive content differs from the approved batch snapshot')
    if file.get('expected_version') and str(metadata.get('version')) != str(file['expected_version']):
        raise ValueError('Drive revision differs from the approved batch snapshot')
    destination.parent.mkdir(parents=True, exist_ok=True)
    temporary = destination.with_name(destination.name + '.download')
    no_reparse(temporary)
    try:
        with open(temporary, 'xb') as handle:
            downloader = MediaIoBaseDownload(handle, service.files().get_media(fileId=file['drive_file_id']), chunksize=1024 * 1024)
            done = False
            while not done:
                _, done = downloader.next_chunk(num_retries=2)
                if handle.tell() > MAX_FILE_BYTES:
                    raise ValueError('Artwork exceeds download limit')
        if temporary.stat().st_size != size or digest(temporary, 'md5') != metadata['md5Checksum']:
            raise ValueError('Drive download integrity mismatch')
        temporary.replace(destination)
        check_magic(destination)
        scan_file(destination)
    finally:
        if temporary.exists():
            temporary.unlink()


def prepare(batch):
    files = validate_batch(batch)
    status(batch['id'], 'CLAIMED')  # Server performs an atomic QUEUED -> CLAIMED transition.
    folder = batch_folder(batch)
    try:
        if folder.exists():
            raise RuntimeError('Existing batch workspace requires operator inspection; refusing overwrite')
        folder.mkdir(parents=True)
        status(batch['id'], 'DOWNLOADING')
        service = drive()
        for file in files:
            destination = contained(folder, file['relative'])
            download(service, file, destination)
            file['sha256'] = digest(destination)
            file['bytes'] = destination.stat().st_size
        write_json(folder / 'batch.json', {'agent_version': VERSION, 'payload_sha256': payload_hash(batch), 'files': files})
        status(batch['id'], 'PREPARED')
        log.info('Batch %s prepared: %s artwork files', batch['id'], len(files))
    except Exception:
        mark_failed(batch)
        raise


def verify_prepared_batch(batch, with_hashes=False):
    expected = validate_batch(batch)
    folder = batch_folder(batch)
    manifest_path = contained(folder, 'batch.json')
    if not manifest_path.is_file() or manifest_path.stat().st_size > 1024 * 1024:
        raise ValueError('Missing or oversized prepared manifest')
    manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
    if manifest.get('payload_sha256') != payload_hash(batch) or len(manifest.get('files', [])) != len(expected):
        raise ValueError('Prepared manifest no longer matches the locked batch')
    recorded = {entry['relative']: entry for entry in manifest['files']}
    result = []
    for file in expected:
        path = contained(folder, file['relative'])
        entry = recorded.get(file['relative'], {})
        if entry.get('drive_file_id') != file['drive_file_id'] or not path.is_file():
            raise ValueError('Prepared file missing or substituted')
        if path.stat().st_size != entry.get('bytes') or digest(path) != entry.get('sha256'):
            raise ValueError('Prepared artwork hash mismatch')
        check_magic(path)
        result.append((path, entry['sha256']) if with_hashes else path)
    allowed = {(item[0] if with_hashes else item).resolve() for item in result} | {manifest_path.resolve(), (folder / 'handoff.json').resolve()}
    for path in folder.rglob('*'):
        no_reparse(path)
        if path.is_file() and path.resolve() not in allowed:
            raise ValueError('Unexpected file found in prepared workspace')
    return folder, result


def mark_failed(batch):
    try:
        status(batch['id'], 'FAILED')
    except Exception:
        log.error('Could not report failure for batch %s; operator must inspect server state', batch['id'])


def handoff(batch):
    try:
        if not HOT or not Path(HOT).is_dir():
            raise ValueError('An existing Edge Print hot folder must be configured')
        if not batch.get('handoff_authorized'):
            raise ValueError('Recorded human handoff confirmation is required')
        folder, verified = verify_prepared_batch(batch, with_hashes=True)
        sources = [source for source, _ in verified]
        journal = contained(folder, 'handoff.json')
        # Once publication starts, never automatically republish even if Edge Print consumes files.
        if journal.exists():
            record = json.loads(journal.read_text(encoding='utf-8'))
            if record.get('state') == 'published' and record.get('payload_sha256') == payload_hash(batch):
                status(batch['id'], 'SENT_TO_RIP')
                return
            raise RuntimeError('Interrupted handoff requires operator inspection; automatic resubmission blocked')
        # Quantity/preset automation has not been validated. Do not silently print one copy of a multi-set job.
        if any(order['quantity'] != 1 for order in batch['payload']['orders']):
            raise RuntimeError('Multi-set hot-folder handoff requires a validated Edge Print quantity adapter; use manual preparation')
        for source, approved_hash in verified:
            scan_file(source)
            if digest(no_reparse(source)) != approved_hash:
                raise ValueError('Prepared artwork changed during scanning')
        write_json(journal, {'state': 'publishing', 'payload_sha256': payload_hash(batch)})
        for source, approved_hash in verified:
            destination = contained(Path(HOT), safe(batch['batch_id'][:50] + '_' + source.parent.name[:20] + '_' + source.stem[:40]) + source.suffix)
            if destination.exists():
                raise ValueError('Hot-folder destination already exists; operator review required')
            temporary = contained(Path(HOT), destination.name + '.lbtmp')
            with open(no_reparse(source), 'rb') as src, open(temporary, 'xb') as dst:
                shutil.copyfileobj(src, dst)
                dst.flush()
                os.fsync(dst.fileno())
            if digest(temporary) != approved_hash:
                raise ValueError('Hot-folder copy hash mismatch')
            temporary.rename(destination)
        write_json(journal, {'state': 'published', 'payload_sha256': payload_hash(batch)})
        status(batch['id'], 'SENT_TO_RIP')
        log.info('Batch %s handed to Edge Print; operator must start physical printing', batch['id'])
    except Exception:
        mark_failed(batch)
        raise


def once():
    operation = 'poll'
    try:
        integrity()
        batch = api('GET', '/factory/batches/next').get('batch')
        if not batch:
            monitor_state(stage='idle', batch_id=None)
            record_operation('poll')
            return False
        validate_batch(batch)
        monitor_state(batch_id=batch['id'], stage='validating')
        if batch.get('status') == 'QUEUED':
            operation = 'prepare'
            prepare(batch)
        elif batch.get('status') == 'HANDOFF_REQUESTED':
            operation = 'handoff'
            monitor_state(stage='handoff')
            handoff(batch)
        else:
            raise ValueError('Server returned an unsupported batch state')
        record_operation(operation)
        return True
    except Exception:
        monitor_state(stage='error')
        record_operation(operation, 'api_unreachable' if operation == 'poll' else 'batch_operation_failed')
        raise


def configure_logging():
    path = no_reparse(HERE / 'logs')
    path.mkdir(exist_ok=True)
    logging.basicConfig(level=logging.INFO, format='%(asctime)s | %(levelname)s | %(message)s', handlers=[
        RotatingFileHandler(path / 'factory-agent.log', maxBytes=2 * 1024 * 1024, backupCount=5, encoding='utf-8'),
        logging.StreamHandler(),
    ])


def main():
    global STOP
    arguments = sys.argv[1:]
    if len(arguments) > 1 or any(arg not in {'--check', '--once', '--health', '--diagnose'} for arg in arguments):
        print('Choose one supported mode: --check, --once, --health or --diagnose. No action was started.')
        raise SystemExit(2)
    arguments = sys.argv[1:]
    if len(arguments) > 1 or any(arg not in {'--check', '--once', '--health', '--diagnose'} for arg in arguments):
        print('Choose one supported mode: --check, --once, --health or --diagnose. No action was started.')
        raise SystemExit(2)
    if '--health' in sys.argv or '--diagnose' in sys.argv:
        try:
            health = monitor_request('GET', '/factory/health')
            if health.get('authenticated') is not True:
                raise ValueError('Unexpected health response')
            print('Factory API authentication: PASSED (read-only; no batches requested)')
            if not health.get('monitoring_ready'):
                print('Monitoring migration is required on the server.')
            if '--diagnose' in sys.argv:
                monitor_state(process_status='checking', stage='idle', batch_id=None)
                result = explicit_diagnostics()
                send_heartbeat()
                print('Diagnostic report sent. View Admin Settings > Factory Agent.')
                if result['error_code']:
                    print('Some checks need attention: ' + result['error_code'])
                    raise SystemExit(1)
        except Exception:
            print('Connection/diagnostic check failed. Check the API URL, matching key, server migration and clock. No print action was requested.')
            raise SystemExit(1)
        return
    try:
        integrity()
    except Exception:
        if '--check' not in sys.argv:
            monitor_state(process_status='error', stage='error', batch_id=None)
            record_operation('configuration', 'configuration')
            try:
                send_heartbeat()
            except Exception:
                pass
        print('Configuration check failed. Check the local .env, credentials and folder settings.')
        raise SystemExit(1)
    OUT.mkdir(parents=True, exist_ok=True)
    configure_logging()
    # OS file lock prevents two local instances claiming/copying the same jobs.
    lock = open(contained(OUT, '.agent.lock'), 'a+b')
    if os.name == 'nt':
        import msvcrt
        lock.seek(0); lock.write(b'0'); lock.flush(); lock.seek(0)
        msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
    else:
        import fcntl
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    def stop(*_):
        global STOP
        STOP = True
    signal.signal(signal.SIGINT, stop)
    signal.signal(signal.SIGTERM, stop)
    if '--check' in sys.argv:
        log.info('Configuration check passed; no API requests or print jobs sent')
        return
    MONITOR_STOP.clear()
    monitor = threading.Thread(target=heartbeat_loop, name='factory-heartbeat', daemon=True)
    monitor.start()
    while not STOP:
        try:
            worked = once()
        except Exception as exc:
            # Do not log URLs, headers, tokens, service-account content or remote response bodies.
            log.error('Agent action failed (%s). Inspect batch state and configuration before retry.', type(exc).__name__)
            if '--once' in sys.argv:
                finish_monitor(monitor)
                raise SystemExit(1)
            worked = False
        if '--once' in sys.argv:
            finish_monitor(monitor)
            break
        for _ in range(2 if worked else POLL):
            if STOP:
                break
            time.sleep(1)
    MONITOR_STOP.set()
    if '--once' not in sys.argv:
        finish_monitor(monitor)


if __name__ == '__main__':
    main()
