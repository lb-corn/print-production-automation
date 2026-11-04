(() => {
    const root = document.querySelector('[data-batch-progress]');
    let pending = false;
    async function refresh() {
        if (!root || pending || document.hidden) return;
        pending = true;
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch(root.dataset.progressUrl, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal});
            if (!response.ok) throw new Error('Status unavailable');
            const data = await response.json();
            root.dataset.state = data.severity;
            const status = document.querySelector('[data-batch-status]');if (status) status.textContent = data.status;
            if (data.status !== 'QUEUED') document.querySelectorAll('[data-return-batch]').forEach(form => { form.hidden = true; });
            root.querySelector('[data-progress-title]').textContent = data.title;
            root.querySelector('[data-progress-message]').textContent = data.message;
            root.querySelector('[data-progress-freshness]').textContent = 'Updated ' + new Date().toLocaleTimeString();
            const bar = root.querySelector('progress');
            const label = root.querySelector('[data-progress-count]');
            const active = ['CLAIMED', 'DOWNLOADING', 'HANDOFF_REQUESTED', 'DELIVERING'].includes(data.status);
            bar.hidden = !active;
            if (active && data.progress) {
                const p = data.progress;
                // Count only fully checked/published files. Bytes refer to the current download.
                bar.max = p.total;bar.value = p.completed;
                if (p.phase === 'downloading' && p.bytes_total) { bar.max = p.bytes_total;bar.value = p.bytes_done; }
                else if (p.phase === 'scanning' && p.completed < p.total) bar.removeAttribute('value');
                label.textContent = `${p.completed} of ${p.total} files ${p.phase === 'publishing' ? 'delivered' : 'checked'}${p.bytes_total && p.phase === 'downloading' ? ' · current download ' + Math.floor(100 * p.bytes_done / p.bytes_total) + '%' : ''}`;
            } else {
                bar.removeAttribute('value');
                label.textContent = active ? 'Waiting for a current worker progress report…' : '';
            }
            for (const key of ['handoff', 'confirm_printed', 'request']) {
                const control = root.querySelector(`[data-progress-action="${key}"]`);
                if (control) control.hidden = !data['can_' + key];
            }
            for (const step of root.querySelectorAll('[data-step]')) {
                const stages = ['QUEUED', 'CLAIMED', 'DOWNLOADING', 'PREPARED', 'HANDOFF_REQUESTED', 'DELIVERING', 'SENT_TO_RIP', 'PRINTED'];
                const current = stages.indexOf(data.status), index = stages.indexOf(step.dataset.step);
                step.classList.toggle('is-current', current === index);
                step.classList.toggle('is-reached', current >= index && current >= 0);
                if (current === index) step.setAttribute('aria-current', 'step');else step.removeAttribute('aria-current');
            }
        } catch {
            root.dataset.state = 'warning';
            root.querySelector('[data-progress-title]').textContent = 'Live progress unavailable';
            root.querySelector('[data-progress-message]').textContent = 'The last displayed state may be outdated. Check your connection or sign in again. Do not resubmit the batch.';
            root.querySelector('progress').hidden = true;
            root.querySelector('[data-progress-count]').textContent = '';
            root.querySelector('[data-progress-freshness]').textContent = 'Status could not refresh';
            root.querySelectorAll('[data-progress-action]').forEach(control => { control.hidden = true; });
        } finally { clearTimeout(timer);pending = false; }
    }
    if (root) { refresh();setInterval(refresh, 5000);document.addEventListener('visibilitychange', refresh); }
    const form = document.querySelector('[data-queue-selection]');
    if (form) {
        const blocked = form.dataset.queueBlocked === '1';
        const checks = [...document.querySelectorAll('[data-queue-item]')];
        const button = form.querySelector('[data-queue-open]');
        const dialog = document.querySelector('[data-queue-dialog]');
        const update = () => { const count = checks.filter(check => check.checked).length;button.disabled = blocked || !count;button.textContent = blocked ? 'Awaiting server update' : `Queue & prepare selected (${count})`; };
        checks.forEach(check => check.addEventListener('change', update));
        form.querySelector('[data-select-all]').addEventListener('change', event => { checks.forEach(check => { check.checked = event.target.checked; });update(); });
        button.addEventListener('click', () => { if (blocked) return;dialog.querySelector('[data-selected-count]').textContent = checks.filter(check => check.checked).length;dialog.showModal(); });
        dialog.querySelector('[data-queue-cancel]').addEventListener('click', () => dialog.close());
        dialog.querySelector('[data-queue-confirm]').addEventListener('click', event => { if (blocked) return;event.target.disabled = true;form.requestSubmit(); });
        form.addEventListener('submit', event => { if (blocked) event.preventDefault(); });
        update();
    }
})();
