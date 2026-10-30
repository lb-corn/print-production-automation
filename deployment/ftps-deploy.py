#!/usr/bin/env python3
"""Resumable, verified FTPS uploader for the Laravel deployment allowlist.

Never deletes remote files; does not touch .env, storage, or live database.
Transfers changed files based on a successful-upload inventory, persisting
checkpoints periodically so a canceled Actions job can resume.
"""
import ftplib
import hashlib
import io
import ipaddress
import json
import os
from pathlib import Path, PurePosixPath
import ssl
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed

ROOT = Path(".deploy").resolve()
STATE = ".lb-production-python-ftps-state.json"
LOCK = threading.Lock()
TIMEOUT = 60
WORKERS = 4
CHECKPOINT_EVERY = 200


def connect(host, tls_name, user, password, remote_root=None):
    ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=TIMEOUT)
    ftp.connect(host, 21)
    ftp.host = tls_name
    ftp.login(user, password)
    ftp.prot_p()
    if remote_root is not None:
        ftp.cwd(remote_root)
    return ftp


def read_state(ftp):
    payload = bytearray()

    def collect(chunk):
        payload.extend(chunk)
        if len(payload) > 8 * 1024 * 1024:
            raise ValueError("Remote inventory exceeds size limit")

    try:
        ftp.retrbinary("RETR " + STATE, collect)
    except ftplib.error_perm as exc:
        if not str(exc).startswith("550"):
            raise
        payload.clear()
        try:
            ftp.retrbinary("RETR " + STATE + ".backup", collect)
        except ftplib.error_perm as backup_exc:
            if not str(backup_exc).startswith("550"):
                raise
    if not payload:
        return {}
    data = json.loads(payload)
    if not isinstance(data, dict) or not all(isinstance(k, str) and isinstance(v, str) for k, v in data.items()):
        raise ValueError("Invalid remote inventory")
    return data


def write_state(ftp, inventory):
    data = json.dumps(inventory, sort_keys=True).encode("utf-8")
    ftp.storbinary("STOR " + STATE + ".pending", io.BytesIO(data))
    try:
        ftp.rename(STATE + ".pending", STATE)
    except ftplib.error_perm as exc:
        # Many FTPS servers require deleting an existing destination first.
        # Keep a backup of the inventory so a failed rename is recoverable.
        try:
            ftp.delete(STATE + ".backup")
        except ftplib.error_perm:
            pass
        try:
            ftp.rename(STATE, STATE + ".backup")
        except ftplib.error_perm:
            raise RuntimeError("Cannot replace remote deployment inventory") from exc
        try:
            ftp.rename(STATE + ".pending", STATE)
        except Exception:
            ftp.rename(STATE + ".backup", STATE)
            raise


def upload_file(ftp, path, relative, remote_root):
    ftp.cwd(remote_root)
    pieces = PurePosixPath(relative).parts
    for part in pieces[:-1]:
        try:
            ftp.cwd(part)
        except ftplib.error_perm:
            ftp.mkd(part)
            ftp.cwd(part)
    # Direct upload is compatible with restricted shared-host FTP servers.
    # A failed transfer is never checkpointed; its file will retry next run.
    with path.open("rb") as handle:
        ftp.storbinary("STOR " + pieces[-1], handle, blocksize=262144)


def main():
    host = os.environ["FTP_SERVER"].strip()
    user = os.environ["FTP_USERNAME"]
    password = os.environ["FTP_PASSWORD"]
    tls_name = os.environ.get("FTP_TLS_SERVER_NAME", "").strip() or host
    if not host or any(c in host for c in "/\\ \t\n\r"):
        raise SystemExit("FTP_SERVER must be a hostname or IP without scheme/path")
    try:
        ipaddress.ip_address(tls_name)
    except ValueError:
        pass
    else:
        raise SystemExit("Use PROD_FTP_TLS_SERVER_NAME matching the server TLS certificate")
    files = sorted(path for path in ROOT.rglob("*") if path.is_file())
    if not files or any(path.is_symlink() for path in ROOT.rglob("*")):
        raise SystemExit("Empty package or symlink not allowed")
    hashes = {}
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        if ".." in PurePosixPath(rel).parts or any(c in rel for c in "\r\n"):
            raise SystemExit("Unsafe upload path")
        hashes[rel] = hashlib.sha256(path.read_bytes()).hexdigest()

    control = connect(host, tls_name, user, password)
    try:
        root = control.pwd()
        entries = control.nlst()
        if root.rstrip("/").endswith("/public") or any(PurePosixPath(x).name == "index.php" for x in entries):
            raise SystemExit("FTP must be rooted at prod/, not prod/public/")
        previous = read_state(control)
        remaining = [(path, path.relative_to(ROOT).as_posix()) for path in files
                     if previous.get(path.relative_to(ROOT).as_posix()) != hashes[path.relative_to(ROOT).as_posix()]]
        print(f"Deploy package {len(files)} files; remaining {len(remaining)}; previously verified {len(files)-len(remaining)}", flush=True)
        if not remaining:
            print("No changes to transfer", flush=True)
            return

        # Each worker uploads sequentially. The coordinator checkpoints only
        # successfully finished uploads, so retries are safe after interruption.
        batches = [remaining[i::WORKERS] for i in range(WORKERS)]
        progress = 0

        def worker(batch):
            ftp = connect(host, tls_name, user, password, root)
            completed = []
            try:
                for path, rel in batch:
                    upload_file(ftp, path, rel, root)
                    completed.append(rel)
                    with LOCK:
                        previous[rel] = hashes[rel]
                return completed
            finally:
                try: ftp.quit()
                except Exception: ftp.close()

        # Upload in small bounded rounds to persist successful work.
        for offset in range(0, len(remaining), CHECKPOINT_EVERY):
            chunk = remaining[offset:offset + CHECKPOINT_EVERY]
            batches = [chunk[i::WORKERS] for i in range(WORKERS)]
            with ThreadPoolExecutor(max_workers=WORKERS) as pool:
                futures = [pool.submit(worker, b) for b in batches if b]
                for future in as_completed(futures):
                    future.result()
            write_state(control, previous)
            progress += len(chunk)
            print(f"Uploaded and checkpointed {progress}/{len(remaining)} changed files", flush=True)
        print("FTPS file transfer complete. Activate Laravel separately on HostGator.", flush=True)
    finally:
        try: control.quit()
        except Exception: control.close()


if __name__ == "__main__":
    main()
