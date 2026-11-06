"""Explicit operator cleanup. Never run automatically or delete installation files."""
import hashlib
import importlib.util
import os
from pathlib import Path
import sys


def checked(path):
    path = Path(path).absolute()
    for part in (path, *path.parents):
        if part.exists() and (part.is_symlink() or getattr(part.lstat(), 'st_file_attributes', 0) & 1024):
            raise RuntimeError('A cleanup path contains a link or junction. Nothing was cleared.')
    return path.resolve()


def cleanup(out, hot, installation):
    installation = checked(installation)
    roots = [checked(out)] + ([checked(hot)] if hot else [])
    for root in roots:
        if root == root.parent or root == installation or installation.is_relative_to(root) or not root.is_relative_to(installation):
            raise RuntimeError('Cleanup requires dedicated folders inside the existing installation.')
    if len(roots) == 2 and (roots[0].is_relative_to(roots[1]) or roots[1].is_relative_to(roots[0])):
        raise RuntimeError('Workspace and hot folder overlap.')
    files = []
    directories = []
    for root in roots:
        if not root.exists():
            continue
        # Snapshot and validate the entire tree before deleting any file.
        for path in root.rglob('*'):
            checked(path)
            if path.is_dir():
                directories.append(path)
                continue
            if not path.is_file():
                raise RuntimeError('Unexpected non-file in print workspace.')
            if path.name in {'.agent.lock', '.listener.lock'}:
                continue
            if path.name.startswith('.env') or path.name.lower().endswith(('.pem', '.p12', '.pfx')) or path.name.lower().startswith(('service-account', 'credentials')):
                raise RuntimeError('Credentials found inside a print folder. Cleanup refused.')
            if root != roots[0] and path.suffix.lower() not in {'.jpg', '.jpeg', '.png', '.tif', '.tiff', '.pdf'}:
                continue
            files.append((path, hashlib.sha256(path.read_bytes()).digest()))
    for path, digest in files:
        checked(path)
        if not path.exists():  # Epson may consume a watched file.
            continue
        if hashlib.sha256(path.read_bytes()).digest() != digest:
            raise RuntimeError('A print file changed during cleanup. Keep the agent and Epson paused and retry.')
        path.unlink()
    for path in sorted(directories, key=lambda p: len(p.parts), reverse=True):
        checked(path)
        if path.exists() and not any(path.iterdir()):
            path.rmdir()
    return len(files)


def main():
    root = checked(Path(r'C:\LuckyBagsFactory'))
    sys.path.insert(0, str(root))
    spec = importlib.util.spec_from_file_location('factory_cleanup_agent', root / 'factory_agent.py')
    agent = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(agent)
    agent.integrity()
    print('Clears generated print workspaces and image/PDF files in the configured incoming folder.')
    print('Installation, .env, credentials, and Epson settings are preserved.')
    print('Disable the LuckyBagsFactoryAgent scheduled task, close its listener, and pause Epson imports first.')
    if input('Type CLEAR PRINT FILES to continue: ').strip() != 'CLEAR PRINT FILES':
        raise RuntimeError('Cleanup cancelled.')
    agent.OUT.mkdir(parents=True, exist_ok=True)
    locks = []
    try:
        # Both locks must be free; never terminate a listener or an active print worker.
        locks.append(agent.acquire_lock('.listener.lock'))
        locks.append(agent.acquire_lock('.agent.lock'))
        count = cleanup(agent.OUT, agent.HOT, root)
        print(f'PC cleanup complete: {count} generated files cleared. Now reset the portal and import fresh Shopify orders.')
        print('After the portal reset succeeds, enable/start the existing scheduled task again.')
    finally:
        for handle in reversed(locks):
            handle.close()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(f'Cleanup stopped: {error}')
        sys.exit(1)
