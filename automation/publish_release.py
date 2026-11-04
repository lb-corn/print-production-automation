"""Publish public agent bundles and retain the latest five immutable versions."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import shutil
import uuid
import zipfile

ALLOWED = set('factory_agent.py job_output.py custom_transfer.py requirements.txt requirements.lock VERSION release.json config.example.env agent-common.ps1 install.ps1 protect-agent.ps1 run-agent.ps1 run-once.ps1 install-autostart.ps1 uninstall-autostart.ps1 agent-info.ps1 update-agent.ps1 README.md SECURITY_REVIEW.md test-connectivity.ps1 report-connection.ps1 install-portal-control.ps1 repair-background-startup.ps1'.split())


def version(value):
    if not re.fullmatch(r'\d+\.\d+\.\d+', value):
        raise ValueError('Invalid public release version')
    return tuple(map(int, value.split('.')))


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def catalog(resources):
    root = Path(resources) / 'factory-agent-releases'
    index = root / 'index.json'
    rows = json.loads(index.read_text(encoding='utf-8')) if index.exists() else []
    if not isinstance(rows, list) or len(rows) > 5:
        raise ValueError('Release catalog must contain at most five entries')
    seen = set()
    for row in rows:
        version(row['version'])
        expected = f"LuckyBags-Factory-Agent-v{row['version']}.zip"
        if row['file'] != expected or row['version'] in seen or digest(root / expected) != row['sha256']:
            raise ValueError('Release catalog contains a duplicate, invalid path or changed package')
        seen.add(row['version'])
    return sorted(rows, key=lambda row: version(row['version']), reverse=True)


def publish(package, resources, summary, published_at=None):
    package = Path(package)
    resources = Path(resources)
    root = resources / 'factory-agent-releases'
    if root.is_symlink():
        raise ValueError('Release directory cannot be a link')
    root.mkdir(parents=True, exist_ok=True)
    rows = catalog(resources)
    with zipfile.ZipFile(package) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)) or not set(names) <= ALLOWED or not {'VERSION', 'release.json', 'factory_agent.py', 'install-portal-control.ps1'} <= set(names):
            raise ValueError('Not an allowlisted public agent package')
        if any(info.file_size > 5_000_000 for info in archive.infolist()):
            raise ValueError('Oversized public source entry')
        current = archive.read('VERSION').decode('utf-8-sig').strip()
        version(current)
        metadata = json.loads(archive.read('release.json').decode('utf-8-sig'))
        if metadata.get('version') != current:
            raise ValueError('Package manifest version mismatch')
        for name in ('factory_agent', 'job_output', 'custom_transfer'):
            if name + '.py' in names and metadata.get(name + '_sha256') != hashlib.sha256(archive.read(name + '.py')).hexdigest():
                raise ValueError('Package source hash mismatch')
        sha = digest(package)
        filename = f'LuckyBags-Factory-Agent-v{current}.zip'
        target = root / filename
        old = next((row for row in rows if row['version'] == current), None)
        if (old and old['sha256'] != sha) or (target.exists() and digest(target) != sha):
            raise ValueError('Published versions are immutable; increment VERSION')
        if not summary.strip() or len(summary) > 160:
            raise ValueError('Provide release notes of 1â€“160 characters')
        if not target.exists():
            shutil.copyfile(package, target)
        entry = old or dict(version=current, file=filename, sha256=sha, bytes=package.stat().st_size,
                            published_at=published_at or datetime.now(timezone.utc).isoformat(), summary=summary.strip())
        all_rows = sorted([row for row in rows if row['version'] != current] + [entry], key=lambda row: version(row['version']), reverse=True)
        retained = all_rows[:5]
        temporary = root / ('.index-' + uuid.uuid4().hex + '.json')
        temporary.write_text(json.dumps(retained, indent=2) + '\n', encoding='utf-8')
        temporary.replace(root / 'index.json')
        if retained[0]['version'] == current:
            shutil.copyfile(target, resources / 'factory-agent-install.zip')
            metadata['portal_zip_sha256'] = sha
            (resources / 'factory-agent-release.json').write_text(json.dumps(metadata, indent=2) + '\n', encoding='utf-8')
            for source, destination in [('install-portal-control.ps1', 'factory-agent-install-portal-control.ps1'), ('repair-background-startup.ps1', 'factory-agent-repair-startup.ps1'), ('report-connection.ps1', 'factory-agent-report-connection.ps1'), ('test-connectivity.ps1', 'factory-agent-test-connectivity.ps1')]:
                if source in names:
                    (resources / destination).write_bytes(archive.read(source))
        for row in all_rows[5:]:
            obsolete = root / row['file']
            if obsolete.parent.resolve() != root.resolve() or digest(obsolete) != row['sha256']:
                raise ValueError('Refusing to prune a changed release')
            obsolete.unlink()
    return retained


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--resources', type=Path, required=True)
    parser.add_argument('--package', type=Path)
    parser.add_argument('--summary')
    parser.add_argument('--published-at')
    parser.add_argument('--validate', action='store_true')
    args = parser.parse_args()
    if args.validate:
        rows = catalog(args.resources)
        latest = json.loads((args.resources / 'factory-agent-release.json').read_text(encoding='utf-8-sig'))
        if not rows or rows[0]['version'] != latest['version'] or rows[0]['sha256'] != latest['portal_zip_sha256'] or digest(args.resources / 'factory-agent-install.zip') != rows[0]['sha256']:
            raise ValueError('Latest package and release catalog are out of sync')
        source = args.resources.parent / 'automation'
        if (source / 'VERSION').exists():
            notes = json.loads((source / 'release-notes.json').read_text(encoding='utf-8'))
            if (source / 'VERSION').read_text(encoding='utf-8-sig').strip() != rows[0]['version'] or notes['version'] != rows[0]['version']:
                raise ValueError('Publish the new VERSION and release notes before deployment')
            for name in ('factory_agent', 'job_output', 'custom_transfer'):
                if digest(source / (name + '.py')) != latest[name + '_sha256']:
                    raise ValueError('Runtime source changed without a new published release')
        print('PASS: Latest five release packages and current download are verified')
    else:
        publish(args.package, args.resources, args.summary or '', args.published_at)
        print('Published public release; latest five versions retained')
