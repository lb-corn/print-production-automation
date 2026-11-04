(() => {
    let queue = Promise.resolve();
    const serial = task => {const next = queue.then(task, task);queue = next.catch(() => {});return next;};
    const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
    const hash = async bytes => [...new Uint8Array(await crypto.subtle.digest('SHA-256', bytes))].map(x => x.toString(16).padStart(2, '0')).join('');
    async function request(url, csrf, data) {
        const response = await fetch(url, {method: data === undefined ? 'GET' : 'POST', headers: {Accept: 'application/json', ...(data === undefined ? {} : {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf})}, body: data === undefined ? undefined : JSON.stringify(data), credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: AbortSignal.timeout(20000)});
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(Object.values(body.errors || {}).flat()[0] || body.message || 'Connection details are unavailable. Refresh and sign in again.');
        return body;
    }
    async function gather(pc) {
        if (pc.iceGatheringState === 'complete') return;
        await new Promise(resolve => {const timer = setTimeout(finish, 12000);function finish() {clearTimeout(timer);pc.removeEventListener('icegatheringstatechange', changed);resolve();}function changed() {if (pc.iceGatheringState === 'complete') finish();}pc.addEventListener('icegatheringstatechange', changed);});
    }
    async function connect(root, options, update, signal) {
        if (!window.RTCPeerConnection || !window.crypto?.subtle) throw new Error('Direct transfer requires HTTPS and a current browser.');
        const id = crypto.randomUUID(), csrf = root.dataset.csrf;
        const pc = new RTCPeerConnection({iceServers: [{urls: 'stun:stun.l.google.com:19302'}]});
        const dc = pc.createDataChannel('lucky-custom-v1', {ordered: true});dc.binaryType = 'arraybuffer';
        let created = false, finished = false, succeeded = false, result, failure, bytes = 0, preview, chunks = [];
        const done = new Promise((resolve, reject) => {result = resolve;failure = reject;});done.catch(() => {});
        const onAbort = () => {failure(new Error('Transfer cancelled.'));pc.close();};signal?.addEventListener('abort', onAbort, {once: true});
        dc.onmessage = async event => {
            try {
                if (typeof event.data === 'string') {
                    const data = JSON.parse(event.data);
                    if (data.type === 'preview' && options.kind === 'PREVIEW') {if (!Number.isInteger(data.bytes) || data.bytes < 1 || data.bytes > 2097152) throw new Error('Invalid PC preview size.');preview = data;}
                    else if (data.type === 'complete' && data.id === id) {
                        if (options.kind === 'PREVIEW') {
                            if (!preview || bytes !== preview.bytes) throw new Error('Incomplete PC preview.');
                            const blob = new Blob(chunks, {type: 'image/jpeg'});if (await hash(await blob.arrayBuffer()) !== preview.sha256) throw new Error('PC preview integrity check failed.');result(blob);
                        } else result(id);
                    }
                } else {
                    if (options.kind !== 'PREVIEW' || !preview || !(event.data instanceof ArrayBuffer) || bytes + event.data.byteLength > preview.bytes) throw new Error('Invalid PC preview data.');
                    bytes += event.data.byteLength;chunks.push(event.data);
                }
            } catch (error) {failure(error);}
        };
        pc.onconnectionstatechange = () => {if (['failed', 'closed'].includes(pc.connectionState) && !finished) failure(new Error('The direct Print PC connection failed. Check the network and try again. No file was uploaded to portal hosting.'));};
        try {
            update('Connecting directly to Print PC…');
            await pc.setLocalDescription(await pc.createOffer());await gather(pc);
            if (signal?.aborted) throw new Error('Transfer cancelled.');
            await request(root.dataset.createUrl, csrf, {id, ...options, file: undefined, offer: pc.localDescription.sdp});created = true;
            const statusUrl = `${root.dataset.statusBase}/${id}`;
            const start = Date.now();let answered = false;
            while (dc.readyState !== 'open') {
                if (signal?.aborted) throw new Error('Transfer cancelled.');
                if (Date.now() - start > 60000) throw new Error('Unable to establish a direct PC connection. Keep the PC online, then try again. This network may block peer connections.');
                const status = await request(statusUrl, csrf);
                if (['FAILED', 'EXPIRED'].includes(status.state)) throw new Error('The Print PC could not start this transfer. Try again with the PC connected.');
                if (status.answer && !answered) {await pc.setRemoteDescription({type: 'answer', sdp: status.answer});answered = true;}
                if (['failed','closed'].includes(pc.connectionState)) throw new Error('This network could not establish a direct PC connection.');
                await pause(500);
            }
            dc.send(JSON.stringify({type: 'start', id}));
            if (options.kind === 'UPLOAD') {
                const file = options.file;
                for (let offset = 0; offset < file.size; offset += 16384) {
                    if (signal?.aborted) throw new Error('Transfer cancelled.');
                    const deadline = Date.now() + 15000;
                    while (dc.bufferedAmount > 262144) {if (dc.readyState !== 'open' || Date.now() > deadline) throw new Error('Direct transfer stalled. Try again.');await pause(20);}
                    dc.send(await file.slice(offset, offset + 16384).arrayBuffer());update('Sending directly to Print PC…', Math.min(100, Math.round(100 * Math.min(file.size, offset + 16384) / file.size)));
                }
                dc.send(JSON.stringify({type: 'finish', id}));update('File sent. Print PC is scanning and adding the label…');
            } else update('Receiving artwork directly from PC…');
            let timer;const timeout = new Promise((_, reject) => {timer = setTimeout(() => reject(new Error('PC validation timed out. Refresh the order before retrying.')), 300000);});
            try {const value = await Promise.race([done, timeout]);finished = true;succeeded = true;return value;} finally {clearTimeout(timer);}
        } finally {
            finished = true;signal?.removeEventListener('abort', onAbort);dc.close();pc.close();
            if (created && !succeeded) request(`${root.dataset.statusBase}/${id}/cancel`, csrf, {}).catch(() => {});
        }
    }
    document.querySelectorAll('[data-custom-review]').forEach(root => {
        const slots = [...root.querySelectorAll('[data-custom-slot]')], confirm = root.querySelector('[data-custom-confirm]'), reviewed = root.querySelector('[data-custom-reviewed]');
        const updateConfirm = () => {if (confirm) confirm.disabled = !reviewed?.checked || slots.some(slot => !slot.dataset.uploadId || slot.dataset.busy === '1');};
        reviewed?.addEventListener('change', updateConfirm);
        for (const slot of slots) {
            const input = slot.querySelector('[data-custom-file]'), drop = slot.querySelector('.custom-file-drop');let objectUrl;
            async function upload(file) {
                if (!file || input.disabled) return;
                const status = slot.querySelector('[data-custom-status]'), bar = slot.querySelector('progress');
                if (!/\.(jpe?g|png|tiff?)$/i.test(file.name) || file.size < 1 || file.size > 104857600) {status.textContent = 'Choose a JPEG, PNG or single-page TIFF up to 100 MB.';return;}
                slot.querySelectorAll('[data-pc-preview]').forEach(e=>e.hidden=true);const view=slot.querySelector('[data-custom-view]');if(view)view.hidden=true;
                input.disabled = true;slot.dataset.busy = '1';slot.dataset.uploadId = '';if (reviewed) reviewed.checked = false;updateConfirm();
                slot.querySelector('[data-file-name]').textContent = file.name;
                if (objectUrl) URL.revokeObjectURL(objectUrl);objectUrl = URL.createObjectURL(file);const image = slot.querySelector('[data-local-preview]');image.onerror=()=>{image.hidden=true;};image.src = objectUrl;image.hidden = false;
                status.textContent = 'Waiting to send directly to PC…';bar.hidden = false;bar.removeAttribute('value');
                const controller = new AbortController(), cancel = slot.querySelector('[data-custom-cancel]');cancel.hidden = false;cancel.onclick = () => controller.abort();
                try {
                    const id = await serial(async () => {if (controller.signal.aborted) throw new Error('Transfer cancelled.');const checksum = await hash(await file.arrayBuffer());return connect(root, {kind: 'UPLOAD', side: slot.dataset.side, filename: file.name, bytes: file.size, sha256: checksum, file}, (message, progress) => {status.textContent = message;if (progress === undefined) bar.removeAttribute('value');else bar.value = progress;}, controller.signal);});
                    slot.dataset.uploadId = id;if(view)view.hidden=false;status.textContent = 'Validated and labeled on Print PC. Ready to confirm.';
                } catch (error) {status.textContent = error.message;}
                finally {slot.dataset.busy = '0';input.disabled = false;bar.hidden = true;cancel.hidden = true;updateConfirm();}
            }
            slot.querySelector('[data-custom-view]')?.addEventListener('click', async event => {
                const button=event.currentTarget,status=slot.querySelector('[data-custom-status]');button.disabled=true;
                try {const blob=await serial(()=>connect(root,{kind:'PREVIEW',side:slot.dataset.side,parent_id:slot.dataset.uploadId},message=>status.textContent=message));if(objectUrl)URL.revokeObjectURL(objectUrl);objectUrl=URL.createObjectURL(blob);const image=slot.querySelector('[data-local-preview]');image.src=objectUrl;image.hidden=false;status.textContent='Review this validated labeled copy before confirming.';}catch(error){status.textContent=error.message;}finally{button.disabled=false;}
            });
            input?.addEventListener('change', () => upload(input.files[0]));
            for (const event of ['dragenter', 'dragover']) drop.addEventListener(event, e => {e.preventDefault();drop.classList.add('is-dragging');});
            for (const event of ['dragleave', 'drop']) drop.addEventListener(event, e => {e.preventDefault();drop.classList.remove('is-dragging');});
            drop.addEventListener('drop', e => {if (e.dataTransfer.files.length === 1) upload(e.dataTransfer.files[0]);});
        }
        confirm?.addEventListener('click', async () => {
            if (confirm.disabled) return;confirm.disabled = true;const result = root.querySelector('[data-custom-result]');result.textContent = 'Confirming artwork…';
            try {const uploads = Object.fromEntries(slots.map(slot => [slot.dataset.side, slot.dataset.uploadId]));const data = await request(root.dataset.confirmUrl, root.dataset.csrf, {confirm_custom: true, uploads});result.textContent = data.message;window.location.reload();}
            catch (error) {result.textContent = error.message;updateConfirm();}
        });updateConfirm();
    });
    document.querySelectorAll('[data-pc-preview]').forEach(root => {
        root.querySelector('[data-pc-preview-open]').addEventListener('click', async event => {
            const button = event.currentTarget, status = root.querySelector('[data-pc-preview-status]');button.disabled = true;
            try {const blob = await serial(() => connect(root, {kind: 'PREVIEW', side: root.dataset.side, parent_id: root.dataset.uploadId}, message => {status.textContent = message;}));const image = root.querySelector('img');if (image.dataset.objectUrl) URL.revokeObjectURL(image.dataset.objectUrl);image.src = URL.createObjectURL(blob);image.dataset.objectUrl = image.src;image.hidden = false;button.hidden = true;status.textContent = 'Received directly from Print PC.';}
            catch (error) {status.textContent = error.message;button.disabled = false;}
        });
    });
})();
