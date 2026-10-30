"""Fail closed on dangerous tracked files and a small set of high-risk source patterns."""
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]


def forbidden(path):
    name = path.lower().replace('\\', '/')
    base = name.rsplit('/', 1)[-1]
    return (
        base == '.env' or (base.startswith('.env.') and base != '.env.example')
        or base in {'tasks.json', 'fa-solid-300.llf', 'fa-solid-500.woff2', 'fa-solid.lfk', 'credentials.json', 'token.json'}
        or (base.startswith('service-account') and base.endswith('.json'))
        or name.startswith(('vendor/', 'node_modules/'))
        or (name.startswith('database/') and (name.endswith('.sqlite') or '.sqlite-' in name))
        or base.endswith(('.pem', '.p12', '.pfx'))
    )


def main():
    paths = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
    bad = [path for path in filter(None, paths) if forbidden(path)]
    modes = subprocess.check_output(['git', 'ls-files', '-s'], cwd=ROOT).decode().splitlines()
    bad.extend(line.split('\t', 1)[-1] for line in modes if line.startswith('120000 '))
    if bad:
        raise SystemExit('BLOCKED tracked files or links:\n' + '\n'.join(bad))
    patterns = [r'eval\s*\(\s*base64_decode', r'base64_decode\s*\(.*(?:eval|assert)',
                r'child_process.{0,80}(?:exec|spawn)', r'curl\s+[^\n|]{0,200}\|\s*(?:bash|sh)',
                r'file_put_contents\s*\(\s*[\'\"](?:\.htaccess|\.user\.ini)']
    hits = []
    for directory in ['app', 'bootstrap', 'public', 'resources', 'routes', 'automation', 'deployment']:
        for path in (ROOT / directory).rglob('*'):
            # Check tracked source only: local virtualenvs/runtime files must never enter the gate/package.
            relative = path.relative_to(ROOT).as_posix()
            if relative not in paths or not path.is_file() or path.suffix.lower() not in {'.php','.js','.mjs','.cjs','.sh','.py','.ps1'}:
                continue
            data = path.read_text(encoding='utf-8-sig')
            for pattern in patterns:
                if re.search(pattern, data, re.I | re.S): hits.append(f'{relative}: {pattern}')
    if hits:
        raise SystemExit('Security review required:\n' + '\n'.join(hits))
    print('Tracked-file and high-risk-pattern gates passed. This is not a malware-free guarantee.')


if __name__ == '__main__':
    main()
