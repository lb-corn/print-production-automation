"""Direct DTLS file transfer. Portal API calls contain signaling/receipts only."""
from __future__ import annotations
import asyncio
import hashlib
import io
import json
from pathlib import Path
import re
import time
import uuid

STUN = 'stun:stun.l.google.com:19302'


def asset_folder(root, identity, output):
    identity = str(uuid.UUID(identity))
    return output.inside(root, Path('custom-artwork') / identity)


def source_files(root, identity, expected_hash, output):
    folder = asset_folder(root, identity, output)
    manifest = output.inside(folder, 'ready.json')
    if not manifest.is_file() or manifest.stat().st_size > 65536:
        raise ValueError('Custom artwork is missing on this PC')
    data = json.loads(manifest.read_text(encoding='utf-8'))
    if data.get('id') != identity or data.get('sha256') != expected_hash:
        raise ValueError('Custom artwork identity changed')
    extension = Path(data.get('filename', '')).suffix.lower()
    if extension not in {'.jpg', '.jpeg', '.png', '.tif', '.tiff'}:
        raise ValueError('Unsupported custom artwork extension')
    source = output.inside(folder, 'original' + extension)
    derived = output.inside(folder, 'print.tif')
    for path, size, checksum in ((source, data['bytes'], data['sha256']), (derived, data['output_bytes'], data['output_sha256'])):
        if not path.is_file() or path.stat().st_size != size or output.digest(path) != checksum:
            raise ValueError('Custom artwork content changed')
    return source, derived, data


def cleanup_asset(root, proof, output):
    if proof.get('cleanup_authorized') is not True or proof.get('reason') not in {'PRINTED', 'DISCARDED'}:
        return False
    folder = asset_folder(root, proof['id'], output)
    manifest = output.inside(folder, 'ready.json')
    if not manifest.is_file() or manifest.stat().st_size > 65536:
        return False
    data = json.loads(manifest.read_text(encoding='utf-8'))
    if data.get('id') != proof['id'] or data.get('sha256') != proof.get('source_sha256'):
        raise ValueError('Custom cleanup proof belongs to another original')
    extension = Path(data['filename']).suffix.lower()
    if extension not in {'.jpg', '.jpeg', '.png', '.tif', '.tiff'}:
        raise ValueError('Invalid custom cleanup path')
    source, derived = output.inside(folder, 'original' + extension), output.inside(folder, 'print.tif')
    owned = {source, derived, manifest}
    if not set(folder.iterdir()).issubset(owned):
        raise ValueError('Unexpected custom artwork file; cleanup blocked')
    for path, checksum in ((source, data['sha256']), (derived, data['output_sha256'])):
        if path.exists():
            if not path.is_file() or output.digest(path) != checksum:
                raise ValueError('Custom artwork changed; cleanup blocked')
        elif not data.get('deleting'):
            raise ValueError('Custom artwork missing before cleanup')
    data['deleting'] = True
    output.atomic_json(manifest, data)
    for path in (source, derived, manifest):
        if path.exists():
            output.guarded(path).unlink()
    folder.rmdir()
    return True


def finalize_upload(folder, metadata, identity, output, scan):
    extension = Path(metadata['filename']).suffix.lower()
    source = output.inside(folder, 'original' + extension)
    if source.stat().st_size != metadata['bytes'] or output.digest(source) != metadata['sha256']:
        raise ValueError('Custom upload checksum mismatch')
    scan(source)
    derived = output.inside(folder, 'print.tif')
    rendering = output.render_label(source, derived, metadata['print_label'], 104857600, metadata['print_label_policy'])
    scan(derived)
    if output.digest(source) != metadata['sha256']:
        raise ValueError('Custom source changed during validation')
    data = {**metadata, 'id': identity, 'output_sha256': output.digest(derived), 'output_bytes': derived.stat().st_size,
            'width': rendering['pixels'][0], 'height': rendering['pixels'][1]}
    output.atomic_json(folder / 'ready.json', data)
    return {key: data[key] for key in ('output_sha256', 'output_bytes', 'width', 'height')} | {'source_sha256': data['sha256']}


async def transfer(identity, root, api, output, scan, ice_servers=None):
    from aiortc import RTCPeerConnection, RTCConfiguration, RTCIceServer, RTCSessionDescription
    identity = str(uuid.UUID(identity))
    job = await asyncio.to_thread(api, 'POST', f'/factory/custom-transfers/{identity}/begin')
    metadata = job['metadata']
    if job['id'] != identity or job['kind'] not in {'UPLOAD', 'PREVIEW'}:
        raise ValueError('Invalid direct-transfer assignment')
    offer = job['offer']
    if not isinstance(offer, str) or len(offer) > 65536 or not offer.startswith('v=0') or offer.count('m=application ') != 1 or re.search(r'^m=(?!application )', offer, re.M):
        raise ValueError('Only an artwork data channel is accepted')
    folder = partial = None
    if job['kind'] == 'UPLOAD':
        if type(metadata.get('bytes')) is not int or not 0 < metadata['bytes'] <= 104857600 or not re.fullmatch(r'[a-f0-9]{64}', metadata.get('sha256', '')):
            raise ValueError('Invalid custom artwork size or digest')
        extension = Path(metadata['filename']).suffix.lower()
        if extension not in {'.jpg', '.jpeg', '.png', '.tif', '.tiff'}:
            raise ValueError('Invalid custom source format')
        folder = asset_folder(root, identity, output)
        folder.mkdir(parents=True, exist_ok=False)
        partial = output.inside(folder, 'receiving.part')
    pc = RTCPeerConnection(RTCConfiguration(iceServers=[RTCIceServer(urls=STUN)] if ice_servers is None else ice_servers))
    complete = asyncio.get_running_loop().create_future()
    state = {'handle': None, 'count': 0, 'hash': hashlib.sha256(), 'started': False, 'finishing': False, 'channels': 0}
    tasks = []

    def fail(error):
        if not complete.done():
            complete.set_exception(error)

    async def validated(channel):
        try:
            receipt = await asyncio.to_thread(finalize_upload, folder, metadata, identity, output, scan)
            await asyncio.to_thread(api, 'POST', f'/factory/custom-transfers/{identity}/report', json={'state': 'READY', 'receipt': receipt})
            channel.send(json.dumps({'type': 'complete', 'id': identity}))
            if not complete.done():
                complete.set_result(True)
        except Exception as exc:
            fail(exc)

    async def preview(channel):
        try:
            _, derived, _ = source_files(root, job['parent_id'], metadata['sha256'], output)
            from PIL import Image
            with Image.open(derived) as image:
                image.thumbnail((1000, 1000))
                buffer = io.BytesIO()
                image.convert('RGB').save(buffer, format='JPEG', quality=85)
            data = buffer.getvalue()
            if len(data) > 2097152:
                raise ValueError('Preview exceeds the direct-transfer limit')
            channel.send(json.dumps({'type': 'preview', 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()}))
            for offset in range(0, len(data), 16384):
                deadline = time.monotonic() + 15
                while channel.bufferedAmount > 262144:
                    if channel.readyState != 'open' or time.monotonic() > deadline:
                        raise TimeoutError('Preview connection stalled')
                    await asyncio.sleep(.02)
                channel.send(data[offset:offset + 16384])
            channel.send(json.dumps({'type': 'complete', 'id': identity}))
            await asyncio.to_thread(api, 'POST', f'/factory/custom-transfers/{identity}/report', json={'state': 'READY'})
            if not complete.done():
                complete.set_result(True)
        except Exception as exc:
            fail(exc)

    @pc.on('datachannel')
    def on_channel(channel):
        state['channels'] += 1
        if state['channels'] != 1 or channel.label != 'lucky-custom-v1' or not channel.ordered or channel.maxRetransmits is not None or channel.maxPacketLifeTime is not None:
            channel.close();fail(ValueError('Unexpected custom transfer channel'));return
        @channel.on('message')
        def on_message(message):
            try:
                if isinstance(message, str):
                    if len(message) > 4096:
                        raise ValueError('Oversized transfer control message')
                    control = json.loads(message)
                    if control.get('id') != identity:
                        raise ValueError('Transfer identity mismatch')
                    if control.get('type') == 'start' and not state['started']:
                        state['started'] = True
                        if job['kind'] == 'PREVIEW':
                            tasks.append(asyncio.create_task(preview(channel)))
                        else:
                            state['handle'] = open(partial, 'xb')
                    elif control.get('type') == 'finish' and job['kind'] == 'UPLOAD' and state['started'] and not state['finishing']:
                        state['finishing'] = True
                        state['handle'].close();state['handle'] = None
                        if state['count'] != metadata['bytes'] or state['hash'].hexdigest() != metadata['sha256']:
                            raise ValueError('Incomplete or changed custom artwork')
                        partial.replace(folder / ('original' + extension))
                        tasks.append(asyncio.create_task(validated(channel)))
                    else:
                        raise ValueError('Unexpected transfer sequence')
                elif isinstance(message, bytes):
                    if job['kind'] != 'UPLOAD' or not state['handle'] or state['finishing'] or len(message) > 65536 or state['count'] + len(message) > metadata['bytes']:
                        raise ValueError('Custom artwork transfer exceeds its authorized size')
                    state['handle'].write(message);state['hash'].update(message);state['count'] += len(message)
                else:
                    raise ValueError('Unsupported transfer message')
            except Exception as exc:
                fail(exc)
    @pc.on('connectionstatechange')
    async def on_connection():
        if pc.connectionState in {'failed', 'closed'} and not state['finishing']:
            fail(ConnectionError('Direct PC connection was interrupted'))
    try:
        await pc.setRemoteDescription(RTCSessionDescription(sdp=offer, type='offer'))
        await pc.setLocalDescription(await pc.createAnswer())
        await asyncio.to_thread(api, 'POST', f'/factory/custom-transfers/{identity}/report', json={'state': 'CONNECTED', 'answer': pc.localDescription.sdp})
        await asyncio.wait_for(complete, timeout=300)
        # Give the reliable channel a chance to flush its final acknowledgement.
        await asyncio.sleep(.3)
    except Exception:
        try:
            await asyncio.to_thread(api, 'POST', f'/factory/custom-transfers/{identity}/report', json={'state': 'FAILED', 'error_code': 'transfer_failed'})
        except Exception:
            pass
        raise
    finally:
        if state['handle']:
            state['handle'].close()
        for task in tasks:
            if not task.done():
                task.cancel()
        await pc.close()
        if partial and partial.exists():
            output.guarded(partial).unlink()
        # Fully validated receipts are retained even if the HTTP outcome was uncertain.
        if folder and not (folder / 'ready.json').exists():
            for name in ('original' + extension, 'print.tif'):
                path = output.inside(folder, name)
                if path.exists():
                    output.guarded(path).unlink()
            if not list(folder.iterdir()):
                folder.rmdir()
