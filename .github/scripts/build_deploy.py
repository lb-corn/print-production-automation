"""Create an allowlisted Laravel web-server package without local data or factory-PC tools."""
from pathlib import Path
import shutil

ROOT = Path(__file__).resolve().parents[2]
TARGET = ROOT / '.deploy'
ALLOW = ['app','bootstrap','config','database/migrations','database/seeders','public','resources','routes','vendor','deployment',
         'artisan','composer.json','composer.lock']


def excluded(relative):
    parts = relative.parts
    name = relative.name.lower()
    return (name.startswith('.env') or name.startswith('service-account') or name in {'credentials.json','token.json','.htaccess'}
            or name.endswith(('.sqlite','.log','.pem','.p12','.pfx','.pyc')) or '.sqlite-' in name
            or any(part in {'.git','__pycache__','.venv','node_modules'} for part in parts)
            or (parts[:2] == ('bootstrap','cache') and name.endswith('.php')))


def main():
    if TARGET.is_symlink() or TARGET.exists():
        raise SystemExit('Deployment staging directory already exists; use a clean CI checkout.')
    TARGET.mkdir()
    count = 0
    for entry in ALLOW:
        source = ROOT / entry
        if not source.exists(): raise SystemExit(f'Required deployment source missing: {entry}')
        for path in ([source] if source.is_file() else source.rglob('*')):
            relative = path.relative_to(ROOT)
            if path.is_symlink(): raise SystemExit(f'Deployment link blocked: {relative}')
            if not path.is_file() or excluded(relative): continue
            destination = TARGET / relative
            destination.parent.mkdir(parents=True,exist_ok=True)
            shutil.copyfile(path,destination)
            count += 1
    for required in ['public/index.php','artisan','vendor/autoload.php','deployment/post-deploy.sh']:
        if not (TARGET / required).is_file(): raise SystemExit(f'Incomplete deployment package: {required}')
    print(f'Allowlisted web deployment package created: {count} files. No .env, credentials, runtime storage, agent, or operator documents included.')


if __name__ == '__main__':
    main()
