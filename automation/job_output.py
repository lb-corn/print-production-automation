"""Deterministic print copies and narrowly owned, PRINTED-only local cleanup."""
from __future__ import annotations
import hashlib
import json
import os
from pathlib import Path
import re
import uuid


def digest(path):
    value = hashlib.sha256()
    with open(path, 'rb') as handle:
        for chunk in iter(lambda: handle.read(1048576), b''):
            value.update(chunk)
    return value.hexdigest()


def payload_digest(payload):
    return hashlib.sha256(json.dumps(payload, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def guarded(path):
    path = Path(os.path.abspath(path))
    for item in (path, *path.parents):
        if item.is_symlink() or (item.exists() and getattr(item.lstat(), 'st_file_attributes', 0) & 0x400):
            raise ValueError('Linked paths are not owned print files')
    return path


def inside(root, relative):
    root = guarded(root).resolve()
    candidate = guarded(root / relative).resolve()
    if candidate == root or not candidate.is_relative_to(root):
        raise ValueError('Print path escapes its owner')
    return candidate


def atomic_json(path, value):
    path = guarded(path)
    temporary = guarded(path.with_name(path.name + '.' + uuid.uuid4().hex + '.tmp'))
    with temporary.open('x', encoding='utf-8') as handle:
        json.dump(value, handle, separators=(',', ':'))
        handle.flush()
        os.fsync(handle.fileno())
    temporary.replace(path)


def render_label(source, destination, label, max_bytes, policy='BOTTOM_WHITE_V2'):
    from PIL import Image, ImageDraw, ImageFont
    if not re.fullmatch(r'[A-Z0-9][A-Z0-9-]{1,119}', label):
        raise ValueError('Invalid locked production label')
    source, destination = guarded(source), guarded(destination)
    if source.suffix.lower() not in {'.jpg','.jpeg','.png','.tif','.tiff'}:
        raise ValueError('Automatic labels require an approved JPEG, PNG or single-page TIFF original')
    temporary = guarded(destination.with_name(destination.name + '.labeltmp'))
    try:
        with Image.open(source, formats=['JPEG', 'PNG', 'TIFF']) as original:
            width, height = original.size
            if width * height > 50000000 or min(width, height) < 100 or getattr(original, 'n_frames', 1) != 1:
                raise ValueError('Labeling requires one bounded production image')
            if original.getexif().get(274, 1) != 1:
                raise ValueError('Normalize source orientation before artwork approval')
            info = dict(original.info)
            image = original.convert('RGBA') if original.mode == 'P' else original.copy()
        if image.mode not in {'RGB', 'RGBA', 'CMYK', 'L'}:
            raise ValueError('Unsupported production image color mode')
        draw = ImageDraw.Draw(image)
        draw.fontmode = "1"
        size = max(6, min(64, round(height * .012)))
        pad = max(1, round(height * .002))
        while True:
            font = ImageFont.load_default(size=size)
            box = draw.textbbox((0, 0), label, font=font)
            text_width, text_height = box[2] - box[0], box[3] - box[1]
            if text_width + 2 * pad <= width * .94:
                break
            size -= 1
            if size < 6:
                raise ValueError('Label cannot fit the production image legibly')
        plate_width, plate_height = text_width + 2 * pad, text_height + 2 * pad
        left = (width - plate_width) // 2
        bottom = height - max(1, round(height * (.04 if policy == 'BOTTOM_WHITE_V1' else .015)))
        top = bottom - plate_height
        white, black = ((0, 0, 0, 0), (0, 0, 0, 255)) if image.mode == 'CMYK' else ((255, 255, 255, 255), (0, 0, 0, 255)) if image.mode == 'RGBA' else ((255, 255, 255), (0, 0, 0)) if image.mode == 'RGB' else (255, 0)
        draw.rectangle((left, top, left + plate_width, bottom), fill=white)
        draw.text((left + pad - box[0], top + pad - box[1]), label, font=font, fill=black)
        settings = {'format': 'TIFF', 'compression': 'tiff_lzw'}
        if info.get('icc_profile'):
            settings['icc_profile'] = info['icc_profile']
        if info.get('dpi'):
            settings['dpi'] = info['dpi']
        destination.parent.mkdir(parents=True, exist_ok=True)
        if destination.exists():
            raise ValueError('Labeled output already exists')
        image.save(temporary, **settings)
        if temporary.stat().st_size > max_bytes:
            raise ValueError('Labeled output exceeds the artwork limit')
        temporary.replace(destination)
        return {'label_bounds': [left, top, left + plate_width, bottom], 'label_font_pixels': size,
                'pixels': [width, height], 'color_mode': image.mode}
    finally:
        if temporary.exists():
            temporary.unlink()


class RetainedJobs:
    def __init__(self, root, hot):
        self.root, self.hot = guarded(root).resolve(), guarded(hot).resolve()
        self.index = self.root / 'retained-jobs.json'

    def load(self):
        if not self.index.exists():
            return {}
        guarded(self.index)
        if self.index.stat().st_size > 16 * 1024 * 1024:
            raise ValueError('Retention index exceeds its limit')
        value = json.loads(self.index.read_text(encoding='utf-8'))
        if not isinstance(value, dict) or len(value) > 5000:
            raise ValueError('Invalid retention index')
        return value

    def save(self, jobs):
        atomic_json(self.index, jobs)

    def register(self, batch, local_paths, hot_files):
        job = batch['batch_id']
        if not re.fullmatch(r'[A-Za-z0-9_-]{1,80}', job):
            raise ValueError('Invalid retained job ID')
        jobs = self.load()
        existing = jobs.get(str(batch['id']))
        if existing and (existing['job_id'] != job or existing['payload_sha256'] != payload_digest(batch['payload']) or existing['state'] == 'cleaned'):
            raise ValueError('Retention owner changed')
        files = []
        for path in local_paths:
            path = guarded(path)
            relative = str(path.relative_to(self.root))
            if Path(relative).parts[0] != job:
                raise ValueError('Workspace file belongs to another job')
            files.append({'relative': relative, 'sha256': digest(path), 'bytes': path.stat().st_size})
        for entry in hot_files:
            if not re.fullmatch(r'[A-Za-z0-9_.-]{1,220}',entry['name']) or not entry['name'].startswith(job + '_'):
                raise ValueError('Hot-folder file belongs to another job')
        jobs[str(batch['id'])] = {'id': batch['id'], 'job_id': job, 'payload_sha256': payload_digest(batch['payload']),
            'state': 'published', 'hot_root': str(self.hot), 'files': files, 'hot_files': hot_files}
        self.save(jobs)

    def pending(self):
        return [entry for entry in self.load().values() if entry.get('state') in {'published', 'deleting'}]

    def cleanup(self, proof):
        # A fresh authenticated server response is required on every attempt, including crash recovery.
        if proof.get('cleanup_authorized') is not True or proof.get('status') != 'PRINTED' or type(proof.get('confirmation_id')) is not int or proof['confirmation_id'] <= 0:
            return False
        jobs = self.load()
        record = jobs.get(str(proof.get('id')))
        if not record or record['state'] == 'cleaned':
            return False
        if record['job_id'] != proof.get('batch_id') or payload_digest(proof.get('payload')) != record['payload_sha256'] or str(self.hot) != record['hot_root']:
            raise ValueError('Print completion does not match the local owner')
        folder = inside(self.root, record['job_id'])
        local = []
        hashes = {}
        for entry in record['files']:
            relative = Path(entry['relative'])
            if relative.parts[0] != record['job_id']:
                raise ValueError('Unowned local cleanup path')
            path = inside(self.root, relative)
            if not path.exists():
                if record['state'] != 'deleting':
                    raise ValueError('Workspace changed before cleanup')
            elif not path.is_file() or path.stat().st_size != entry['bytes'] or digest(path) != entry['sha256']:
                raise ValueError('Workspace file changed before cleanup')
            local.append(path)
            hashes[path] = entry['sha256']
        expected = set(local)
        parents = {folder}
        for path in local:
            parents.update(parent for parent in path.parents if parent == folder or parent.is_relative_to(folder))
        if folder.exists():
            todo = [folder]
            while todo:
                directory = todo.pop()
                for path in directory.iterdir():
                    guarded(path)
                    if path.is_dir():
                        if path not in parents:
                            raise ValueError('Unowned directory prevents cleanup')
                        todo.append(path)
                    elif path not in expected:
                        raise ValueError('Unowned file prevents cleanup')
        incoming = []
        for entry in record['hot_files']:
            name = entry['name']
            if not re.fullmatch(r'[A-Za-z0-9_.-]{1,220}',name) or not name.startswith(record['job_id'] + '_'):
                raise ValueError('Unowned hot-folder cleanup path')
            path = inside(self.hot, name)
            if path.exists() and (not path.is_file() or digest(path) != entry['sha256']):
                raise ValueError('Hot-folder file changed before cleanup')
            incoming.append(path)
            hashes[path] = entry['sha256']
        record['state'] = 'deleting'
        self.save(jobs)
        for path in incoming + local:
            if path.exists():
                guarded(path)
                if digest(path) != hashes[path]:
                    raise ValueError('Owned file changed during cleanup')
                path.unlink()
        for directory in sorted(parents, key=lambda value: len(value.parts), reverse=True):
            if directory.exists():
                guarded(directory).rmdir()
        jobs[str(record['id'])] = {key: record[key] for key in ('id', 'job_id', 'payload_sha256')}
        jobs[str(record['id'])]['state'] = 'cleaned'
        self.save(jobs)
        return True
