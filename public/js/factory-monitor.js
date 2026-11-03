(() => {
    const badge = document.querySelector('[data-factory-connection]');
    const monitor = document.querySelector('[data-agent-monitor]');
    const labels = {online: 'Connected', offline: 'Offline', never_connected: 'Not connected', unavailable: 'Unavailable', checking: 'Checking…'};
    let badgeExpiry;
    function setBadge(state, count = 0) {
        if (!badge) return;
        badge.dataset.state = state;
        badge.querySelector('b').textContent = state === 'online' && count > 1 ? `${count} connected` : labels[state] || labels.unavailable;
        badge.title = state === 'online' ? 'Recent authenticated heartbeat. This does not verify printer readiness.' : 'Connection status only. Installation and diagnostics are available to administrators.';
    }
    async function read(url) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch(url, {headers: {Accept: 'application/json, */*;q=0.5'}, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal});
            if (!response.ok) {
                const error = new Error(response.status === 401 ? 'Your session expired. Sign in again.' : response.status === 403 ? 'Administrator access is required.' : response.status === 429 ? 'Status requests are temporarily limited. Please wait and retry.' : `Status service returned HTTP ${response.status}. Please retry.`);
                throw error;
            }
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Status response was not JSON. Refresh the page and sign in again if needed.');
            return await response.json();
        } finally { clearTimeout(timeout); }
    }
    let badgePending = false;
    async function refreshBadge() {
        if (!badge || document.hidden || badgePending) return;
        badgePending = true;
        const startedAt = Date.now();
        try {
            const data = await read(badge.dataset.statusUrl);
            clearTimeout(badgeExpiry);
            setBadge(data.state, data.online);
            if (data.state === 'online') badgeExpiry = setTimeout(() => setBadge('offline'), Math.max(0, (data.expires_in_seconds || 0) * 1000 - (Date.now() - startedAt)));
        } catch { clearTimeout(badgeExpiry); setBadge('unavailable'); }
        finally { badgePending = false; }
    }
    function node(tag, text, className) {
        const el = document.createElement(tag);
        if (text !== undefined) el.textContent = text;
        if (className) el.className = className;
        return el;
    }
    function date(value) { return value ? new Date(value).toLocaleString() : 'Never reported'; }
    function check(value, current) { return !current || value === null || value === undefined ? 'Unknown — run diagnostics' : value ? 'Passed' : 'Needs attention'; }
    let snapshot, receivedAt, monitorPending = false;
    function render(data, unavailable = false, message = '') {
        if (!monitor) return;
        const notice = monitor.querySelector('[data-agent-notice]');
        notice.textContent = unavailable ? (message || monitor.dataset.error || 'Status could not refresh. Check the connection and retry.') + ' Displayed reports are historical.' : !data.ready ? 'Connection monitoring is not activated on the server yet.' : !data.agents.length ? 'No connection report has been received from a Factory PC.' : `Last checked ${date(data.server_time)}`;
        notice.dataset.state = unavailable || !data.ready ? 'warning' : 'info';
        const cards = monitor.querySelector('[data-agent-cards]');
        const expanded = new Set([...cards.querySelectorAll('.agent-report')].filter(card => card.querySelector('details')?.open).map(card => card.dataset.agentId));
        cards.replaceChildren();
        const serverNow = Date.parse(data.server_time) + Math.max(0, Date.now() - (receivedAt || Date.now()));
        for (const agent of data.agents) {
            const t = agent.telemetry;
            const online = !unavailable && agent.online && serverNow - Date.parse(agent.last_seen_at) < data.offline_after_seconds * 1000;
            const diagnostic = online && agent.diagnostics_fresh && serverNow - (t.diagnostics?.checked_at_ms || 0) < 600000;
            const card = node('section', undefined, 'panel agent-report');
            card.dataset.agentId = agent.agent_id;
            const heading = node('div', undefined, 'agent-report-heading');
            const identity = node('div');identity.append(node('h3', 'Factory PC'), node('small', agent.agent_id));
            heading.append(identity, node('span', unavailable ? 'Status unknown' : online ? 'Online' : 'Offline', `agent-state ${unavailable ? 'unknown' : online ? 'online' : 'offline'}`));card.append(heading);
            const table = node('dl', undefined, 'agent-checks');
            const row = (label, value, mono = false) => { const item = node('div');item.append(node('dt', label), node('dd', value, mono ? 'agent-monospace' : undefined));table.append(item); };
            row('Last connection report', date(agent.last_seen_at));
            row('Installed agent version', t.agent_version);
            row('Report source', t.reporter_mode === 'connection_only' ? 'Connection monitor only — no production worker started' : 'Production agent');
            row('Installed source SHA256', t.source_sha256, true);
            row('Python / Windows', `${t.python_version} / ${t.windows_version}`);
            row('API authentication', online ? 'Authenticated at this heartbeat' : 'Last authenticated at the reported heartbeat; not current');
            row('Agent process / stage', `${online ? t.process_status : 'Last reported: ' + t.process_status} / ${t.stage.replaceAll('_', ' ')}`);
            row('Current batch', t.reporter_mode === 'connection_only' ? 'Not checked by the connection monitor' : online && t.batch_id ? `Database batch #${t.batch_id}` : 'None currently reported');
            card.append(table);
            const diagnostics = node('details', undefined, 'agent-activity');diagnostics.append(node('summary', 'Reported diagnostics'));
            const checks = node('dl', undefined, 'agent-checks');
            for (const [label,value] of [['Google Drive',check(t.diagnostics?.drive_connected,diagnostic)],['Defender scan',check(t.diagnostics?.defender_scan,diagnostic)],['Workspace write test',check(t.diagnostics?.workspace_writable,diagnostic)],['Hot-folder write test',check(t.diagnostics?.hot_folder_writable,diagnostic)],['Printer readiness','Unknown — operator verification required']]) {
                const item=node('div');item.append(node('dt',label),node('dd',value));checks.append(item);
            }
            diagnostics.append(checks);card.append(diagnostics);
            for (const [key, label] of [['last_success', 'Last successful operation'], ['last_failure', 'Last failed operation']]) {
                const op = t[key];row(label, op ? `${op.kind} · ${date(op.at_ms)}${op.error_code ? ' · ' + (data.errors[op.error_code] || 'Inspect local logs.') : ''}` : 'None reported');
            }
            if (t.diagnostics?.error_code) row('Last diagnostic issue', data.errors[t.diagnostics.error_code] || 'Inspect local logs.');
            const details = node('details', undefined, 'agent-activity');details.append(node('summary', 'Recent activity'));
            details.open = expanded.has(agent.agent_id);
            const list = node('ol');
            for (const event of agent.activity) {
                const failure = event.last_failure?.error_code;
                list.append(node('li', `${date(event.at)} · ${event.stage.replaceAll('_', ' ')}${event.diagnostic ? ' · diagnostic report' : ''}${failure ? ' · ' + (data.errors[failure] || 'Inspect local logs.') : ''}`));
            }
            details.append(list);card.append(details);cards.append(card);
        }
    }
    async function refreshMonitor() {
        if (!monitor || document.hidden || monitorPending) return;
        monitorPending = true;
        const startedAt = Date.now();
        const button = monitor.querySelector('[data-agent-refresh]');button.disabled = true;button.textContent = 'Checking…';
        try { snapshot = await read(monitor.dataset.statusUrl); receivedAt = startedAt;monitor.dataset.stale = '0';render(snapshot); }
        catch (error) { monitor.dataset.stale = '1';monitor.dataset.error = error.name === 'AbortError' ? 'Status request timed out. Retry Refresh status.' : error.message === 'Failed to fetch' ? 'The status service could not be reached. Refresh the page and retry.' : error.message;if (snapshot) render(snapshot, true,monitor.dataset.error);else monitor.querySelector('[data-agent-notice]').textContent = monitor.dataset.error; }
        finally { monitorPending = false;button.disabled = false;button.textContent = 'Refresh status'; }
    }
    if (monitor) {
        try { snapshot = JSON.parse(monitor.querySelector('[data-agent-initial]').textContent);receivedAt = Date.now();render(snapshot); } catch { /* The authenticated read below retries. */ }
        monitor.querySelector('[data-agent-refresh]').addEventListener('click', refreshMonitor);
        setInterval(refreshMonitor, 15000);
        // Age healthy reports even if the next HTTP refresh is delayed or fails.
        let ageState = '';
        setInterval(() => {
            if (document.hidden || !snapshot || monitorPending) return;
            const now = Date.parse(snapshot.server_time) + Date.now() - receivedAt;
            const state = JSON.stringify(snapshot.agents.map(agent => [now - Date.parse(agent.last_seen_at) < snapshot.offline_after_seconds * 1000, now - (agent.telemetry.diagnostics?.checked_at_ms || 0) < 600000]));
            if (state !== ageState) { ageState = state;render(snapshot, monitor.dataset.stale === '1'); }
        }, 1000);
        refreshMonitor();
    }
    if (badge) { refreshBadge();setInterval(refreshBadge, 30000); }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { setBadge('checking');refreshBadge();refreshMonitor(); } });
    globalThis.addEventListener?.('pageshow', event => { if (event.persisted) { setBadge('checking');refreshBadge();refreshMonitor(); } });
})();
