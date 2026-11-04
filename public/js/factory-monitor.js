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
            const identity = node('div');identity.append(node('h3', 'Printer PC'));
            heading.append(identity, node('span', unavailable ? 'Status unknown' : online ? 'Online' : 'Offline', `agent-state ${unavailable ? 'unknown' : online ? 'online' : 'offline'}`));card.append(heading);
            const table = node('dl', undefined, 'agent-checks');
            const row = (target,label,value,mono=false) => { const item=node('div');item.append(node('dt',label),node('dd',value,mono?'agent-monospace':undefined));target.append(item); };
            row(table,'Last report',date(agent.last_seen_at));row(table,'Agent version',t.agent_version);
            row(table,'Activity',!online?'Not currently reporting':t.reporter_mode==='connection_only'?'Connection monitor only — worker setup required':t.batch_id?`Batch #${t.batch_id} · ${t.stage.replaceAll('_',' ')}`:'Ready for authorized jobs');
            card.append(table);
            if(monitor.dataset.handoffUrl && /^[a-f0-9-]{36}$/.test(agent.agent_id)) {
                const controls=node('div',undefined,'agent-control');controls.append(node('p',!agent.handoff_control_ready?'Apply the server database update to use handoff control.':agent.handoff_blocked?'Handoff blocked for all users. Preparation can continue.':'Handoff allowed. Every delivery still requires signed user approval.'));
                const form=node('form');form.method='post';form.action=monitor.dataset.handoffUrl.replace('AGENT_ID',agent.agent_id);
                for(const [name,value] of [['_token',monitor.dataset.csrf],['blocked',agent.handoff_blocked?'0':'1'],['confirm_control','1']]){const input=node('input');input.type='hidden';input.name=name;input.value=value;form.append(input);}
                const button=node('button',agent.handoff_blocked?'Allow handoff':'Block handoff',agent.handoff_blocked?'primary':'chip');button.type='submit';button.disabled=unavailable||!agent.handoff_control_ready;
                form.addEventListener('submit',event=>{if(!confirm(agent.handoff_blocked?'Allow handoff for this PC? Previously signed waiting handoffs may proceed.':'Block new handoffs for everyone? A delivery already started may finish safely. This does not stop a physical print.'))event.preventDefault();});
                form.append(button);controls.append(form);card.append(controls);
            }

            const installed=(t.agent_version||'0.0.0').split('.').map(Number), available=(monitor.dataset.packageVersion||'0.0.0').split('.').map(Number);
            const needsOutputUpdate=available.some((part,index)=>part>(installed[index]||0) && available.slice(0,index).every((value,i)=>value===(installed[i]||0)));
            if(needsOutputUpdate && monitor.dataset.updaterUrl) {
                const update=node('details',undefined,'agent-activity portal-setup');update.append(node('summary',`Update existing agent to ${monitor.dataset.packageVersion}`));
                update.append(node('p','On the Print PC, download this one updater and open it from Downloads. It downloads the versioned package, updates C:\\LuckyBagsFactory, and restarts the background listener. No files need to be moved into the installation folder.'));
                const link=node('a',`Download updater v${monitor.dataset.packageVersion}`,'primary');link.href=monitor.dataset.updaterUrl;update.append(link);
                update.append(node('p','Your .env, credentials, agent identity and print workspaces are preserved. Finish active work first; the updater refuses busy or uncertain jobs. Run as the usual printer operator.'));
                update.append(node('p','No new .env settings are required. If you explicitly pinned the old version or source hash, the updater shows only the exact manual pin changes needed. Download links expire after 48 hours.'));
                card.append(update);
            }
            const details=node('details',undefined,'agent-activity');details.open=expanded.has(agent.agent_id);details.append(node('summary','Technical details'));
            const technical=node('dl',undefined,'agent-checks');
            row(technical,'Agent ID',agent.agent_id,true);
            row(technical,'Report source',t.reporter_mode==='connection_only'?'Connection monitor only — no production worker started':t.reporter_mode==='listener'?'Background listener':t.reporter_mode==='managed_worker'?'Temporary production worker':'Production agent');
            row(technical,'Source SHA256',t.source_sha256,true);row(technical,'Python / Windows',`${t.python_version} / ${t.windows_version}`);
            row(technical,'API authentication',online?'Authenticated at this report':'Last report only; not current');
            for(const [key,label] of [['last_success','Last successful operation'],['last_failure','Last failed operation']]) {
                const op=t[key];if(op)row(technical,label,`${op.kind} · ${date(op.at_ms)}${op.error_code?' · '+(data.errors[op.error_code]||'Inspect local logs.'):''}`);
            }
            if(t.diagnostics) {
                row(technical,'Diagnostic report',date(t.diagnostics.checked_at_ms)+(diagnostic?'':' · historical'));
                for(const [key,label] of [['drive_connected','Google Drive'],['defender_scan','Defender scan'],['workspace_writable','Workspace write test'],['hot_folder_writable','Hot-folder write test']]) {
                    if(t.diagnostics[key]!==null && t.diagnostics[key]!==undefined)row(technical,label,check(t.diagnostics[key],diagnostic));
                }
            }
            details.append(technical);card.append(details);cards.append(card);

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
