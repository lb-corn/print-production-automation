const filters = document.querySelectorAll('[data-filter]');
const cards = document.querySelectorAll('.verification-cards .job-card');
function updateDesignCounts() {
    const state=document.querySelector('[data-filter-group="state"].active')?.dataset.filter || 'any';
    const totals={all:0,single:0,double:0,custom:0};
    cards.forEach(card=>{if(state!=='any' && card.dataset.workflow!==state)return;totals.all++;if(card.dataset.custom==='yes')totals.custom++;else if(card.dataset.type in totals)totals[card.dataset.type]++;});
    document.querySelectorAll('[data-design-count]').forEach(count=>count.textContent=totals[count.dataset.designCount]??0);
}
updateDesignCounts();
filters.forEach(button => {
    button.setAttribute('aria-pressed', String(button.classList.contains('active')));
    button.addEventListener('click', () => {
        filters.forEach(other => {
            if (other.dataset.filterGroup !== button.dataset.filterGroup) return;
            other.classList.toggle('active', other === button);
            other.setAttribute('aria-pressed', String(other === button));
        });
        const type = document.querySelector('[data-filter-group="type"].active')?.dataset.filter || 'all';
        const state = document.querySelector('[data-filter-group="state"].active')?.dataset.filter || 'any';
        let visible = 0;
        cards.forEach(card => {
            const matchesType = type === 'all' || (type === 'custom' ? card.dataset.custom === 'yes' : card.dataset.type === type && card.dataset.custom !== 'yes');
            card.hidden = !(matchesType && (state === 'any' || card.dataset.workflow === state));
            if (!card.hidden) visible++;
        });
        updateDesignCounts();
        const empty = document.querySelector('[data-filter-empty]');
        if (empty) empty.hidden = visible > 0 || cards.length === 0;
    });
});
const selectedInputs = () => [...document.querySelectorAll('.review-select')];
const visibleInputs = () => selectedInputs().filter(input => !input.closest('.job-card').hidden);
const selectVisible = document.querySelector('[data-select-visible]');
function updateSelection() {
    const selected = selectedInputs().filter(input => input.checked);
    const visible = visibleInputs();
    const checkedVisible = visible.filter(input => input.checked).length;
    const bulkToolbar = document.querySelector('#bulk-review');
    if (bulkToolbar) bulkToolbar.hidden = visible.length === 0;
    if (selectVisible) {
        selectVisible.checked = visible.length > 0 && checkedVisible === visible.length;
        selectVisible.indeterminate = checkedVisible > 0 && checkedVisible < visible.length;
        selectVisible.disabled = visible.length === 0;
    }
    const count = document.querySelector('[data-selection-count]');
    if (count) count.textContent = `${selected.length} selected`;
    document.querySelectorAll('[data-bulk-action], [data-clear-selection]').forEach(button => button.disabled = selected.length === 0);
    selectedInputs().forEach(input => input.closest('.job-card').classList.toggle('is-selected', input.checked));
}
selectedInputs().forEach(input => {
    input.addEventListener('click', event => event.stopPropagation());
    input.addEventListener('change', updateSelection);
});
selectVisible?.addEventListener('change', () => {
    visibleInputs().forEach(input => input.checked = selectVisible.checked);
    updateSelection();
});
document.querySelector('[data-clear-selection]')?.addEventListener('click', () => {
    selectedInputs().forEach(input => input.checked = false);
    updateSelection();
});
// Changing filters clears selection so bulk actions never include hidden orders.
filters.forEach(button => button.addEventListener('click', () => {
    selectedInputs().forEach(input => input.checked = false);
    updateSelection();
}));
document.querySelector('#bulk-review')?.addEventListener('submit', event => {
    const count = selectedInputs().filter(input => input.checked).length;
    const action = event.submitter?.value === 'CORRECT' ? 'Approve' : 'Reject';
    if (!count || !confirm(`${action} artwork for ${count} selected orders?`)) event.preventDefault();
});
updateSelection();

// Save reviews in place; keep card height, open state, filter and scroll unchanged.
const bulkForm = document.querySelector('#bulk-review');
function reviewFeedback(card, message, error = false) {
    const feedback = card.querySelector('.decision');
    if (!feedback) return;
    feedback.textContent = message;
    feedback.classList.toggle('save-error', error);
}
function applyReviewResult(result) {
    for (const item of result.items) {
        const card = document.querySelector(`[data-item-id="${item.id}"]`);
        if (!card) continue;
        card.dataset.workflow = item.workflow;
        card.dataset.ready = item.ready ? 'yes' : 'no';
        card.dataset.decision = item.decision || '';
        card.classList.toggle('approved-card', item.workflow === 'approved');
        card.classList.toggle('wrong-card', item.workflow === 'rejected');
        const label = item.workflow === 'approved' ? 'Approved' : item.workflow === 'rejected' ? 'Rejected' : 'Needs review';
        card.querySelector('.review-state').textContent = label;
        reviewFeedback(card, `${item.workflow === 'rejected' ? '✕' : '✓'} ${label} · Saved`);
        const checkbox = card.querySelector('.review-select');
        if (checkbox) checkbox.checked = false;
        // Leave the reviewed card visible until the user next changes filters.
    }
    for (const [state, count] of Object.entries(result.summary)) {
        const total = document.querySelector(`.review-total.${state} strong`);
        if (total) total.textContent = count;
    }
    updateDesignCounts();
    if (result.workspace) updateWorkspaceStats(result.workspace);
    updateSelection();
}
async function saveReview(form, submitter) {
    const isBulk = form === bulkForm;
    const affected = isBulk
        ? selectedInputs().filter(input => input.checked).map(input => input.closest('.job-card'))
        : [form.closest('.job-card')];
    if (!affected.length || document.querySelector('[data-review-saving]')) return;
    const data = new FormData(form);
    if (submitter?.name) data.set(submitter.name, submitter.value);
    affected.forEach(card => { card.dataset.reviewSaving = 'yes'; card.setAttribute('aria-busy', 'true'); reviewFeedback(card, 'Saving…'); });
    const controls = [...document.querySelectorAll('.individual-actions button, [data-bulk-action], .review-select, [data-select-visible], [data-clear-selection], [data-filter]')];
    const disabled = controls.map(control => control.disabled);
    controls.forEach(control => control.disabled = true);
    try {
        const response = await fetch(form.action, {
            method: 'POST', body: data, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (response.redirected) throw new Error('Your session may have expired. Refresh and sign in before retrying.');
        const result = await response.json();
        if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Unable to save. Please retry.');
        if (!Array.isArray(result.items) || !result.summary) throw new Error('Could not confirm the save. Refresh to check the order status.');
        applyReviewResult(result);
    } catch (error) {
        affected.forEach(card => reviewFeedback(card, error.message || 'Connection interrupted. Refresh to check the status before retrying.', true));
        // Keep selections and previous decisions available for retry.
    } finally {
        controls.forEach((control, index) => control.disabled = disabled[index]);
        affected.forEach(card => { delete card.dataset.reviewSaving; card.removeAttribute('aria-busy'); });
        updateSelection();
    }
}
document.querySelectorAll('.individual-actions form').forEach(form => form.addEventListener('submit', event => {
    event.preventDefault();
    saveReview(form, event.submitter);
}));
bulkForm?.addEventListener('submit', event => {
    // The earlier handler performs the bulk confirmation.
    if (event.defaultPrevented) return;
    event.preventDefault();
    saveReview(bulkForm, event.submitter);
});

// Preserve context for ordinary POST/redirect actions returning to the same page.
const contextKey = 'production-form-context';
try {
    const saved = JSON.parse(sessionStorage.getItem(contextKey) || 'null');
    sessionStorage.removeItem(contextKey);
    if (saved && saved.path === location.pathname && Date.now() - saved.time < 120000) {
        for (const [group, value] of Object.entries(saved.filters || {})) {
            document.querySelector(`[data-filter-group="${group}"][data-filter="${value}"]`)?.click();
        }
        for (const card of cards) card.open = saved.open?.includes(card.dataset.itemId) || false;
        const restore = () => window.scrollTo({ top: saved.y, left: 0, behavior: 'instant' });
        requestAnimationFrame(restore);
        window.addEventListener('load', restore, { once: true });
    }
} catch (_) { /* Storage can be unavailable in private browser sessions. */ }
document.addEventListener('submit', event => {
    if (event.defaultPrevented || event.target.method?.toLowerCase() !== 'post') return;
    try {
        sessionStorage.setItem(contextKey, JSON.stringify({
            path: location.pathname, y: window.scrollY, time: Date.now(),
            filters: Object.fromEntries([...document.querySelectorAll('[data-filter].active')].map(button => [button.dataset.filterGroup,button.dataset.filter])),
            open: [...cards].filter(card => card.open).map(card => card.dataset.itemId),
        }));
    } catch (_) { /* Native form submission remains available. */ }
});

const printDialog = document.querySelector('#print-confirmation');
const signedPrintForm = document.querySelector('[data-signed-print-form]');
document.querySelectorAll('[data-print-confirm]').forEach(button => button.addEventListener('click', () => {
    signedPrintForm.reset();
    signedPrintForm.action = button.dataset.confirmUrl;
    const action = button.dataset.printConfirm;
    signedPrintForm.dataset.action = action;
    printDialog.querySelector('[data-confirm-phrase]').textContent = action;
    printDialog.querySelector('[data-confirm-description]').textContent = action === 'HANDOFF'
        ? 'Authorize delivery to Epson Edge Print. Auto Print must remain OFF; an operator reviews and starts physical printing.'
        : 'Confirm that the entire batch has physically finished printing. Delivery to Edge Print alone does not mean it is printed.';
    printDialog.showModal();
}));
document.querySelector('[data-cancel-print]')?.addEventListener('click', () => printDialog.close());
signedPrintForm?.addEventListener('submit', event => {
    const phrase = signedPrintForm.elements.confirmation_phrase;
    phrase.setCustomValidity(phrase.value === signedPrintForm.dataset.action ? '' : `Type ${signedPrintForm.dataset.action} exactly.`);
    if (!signedPrintForm.reportValidity()) { event.preventDefault(); return; }
    if (!confirm('Final confirmation: record your name and authorize this batch action?')) event.preventDefault();
});
signedPrintForm?.elements.confirmation_phrase.addEventListener('input', event => event.target.setCustomValidity(''));

function updateWorkspaceStats(stats) {
    for (const [key, value] of Object.entries(stats)) {
        if (!Number.isInteger(value) || value < 0) continue;
        document.querySelectorAll(`[data-workspace-count="${key}"]`).forEach(element => element.textContent = value);
    }
    document.querySelectorAll('[data-workspace-live]').forEach(element => element.textContent = 'Live');
}
const workspaceStatus = document.querySelector('[data-workspace-stats]');
let workspaceRefreshing = false;
async function refreshWorkspaceStats() {
    if (!workspaceStatus || document.hidden || workspaceRefreshing) return;
    workspaceRefreshing = true;
    try {
        const response = await fetch(workspaceStatus.dataset.statusUrl,{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store'});
        if (!response.ok || response.redirected) throw new Error('Status refresh unavailable');
        updateWorkspaceStats(await response.json());
    } catch (_) {
        document.querySelectorAll('[data-workspace-live]').forEach(element => element.textContent = 'Updates paused');
    } finally { workspaceRefreshing = false; }
}
if (workspaceStatus) {
    setInterval(refreshWorkspaceStats,60000);
    document.addEventListener('visibilitychange',()=>{if (!document.hidden) refreshWorkspaceStats();});
}

// Native details keeps the account menu usable even before JavaScript loads.
document.querySelectorAll('[data-account-menu]').forEach(menu => {
    const trigger = menu.querySelector('summary');
    trigger.setAttribute('aria-expanded', String(menu.open));
    menu.addEventListener('toggle', () => trigger.setAttribute('aria-expanded', String(menu.open)));
    document.addEventListener('click', event => {
        if (menu.open && !menu.contains(event.target)) menu.open = false;
    });
    menu.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menu.open) {
            menu.open = false;
            trigger.focus();
        }
    });
});

// The link remains a standalone confirmation page when dialogs are unavailable.
const deleteDialog = document.querySelector('[data-delete-dialog]');
if (deleteDialog && typeof deleteDialog.showModal === 'function') {
    const deleteForm = deleteDialog.querySelector('[data-delete-form]');
    let deleteTrigger;
    document.querySelectorAll('[data-delete-user]').forEach(link => {
        link.addEventListener('click', event => {
            event.preventDefault();
            deleteTrigger = link;
            deleteForm.reset();
            deleteForm.action = link.dataset.deleteAction;
            deleteDialog.querySelector('[data-delete-target]').textContent = `${link.dataset.userName} · ${link.dataset.userEmail}`;
            deleteDialog.querySelector('#modal-delete-email').placeholder = link.dataset.userEmail;
            deleteDialog.showModal();
        });
    });
    deleteDialog.querySelectorAll('[data-close-delete]').forEach(button => button.addEventListener('click', () => deleteDialog.close()));
    deleteDialog.addEventListener('close', () => {
        deleteForm.reset();
        deleteTrigger?.focus();
    });
}

// Clipboard content is plain filename text; never interpreted as HTML.
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-filename]');
    if (!button) return;
    try {
        await navigator.clipboard.writeText(button.dataset.copyFilename);
        button.textContent = 'Copied';
    } catch (_) {
        button.textContent = 'Copy unavailable — select the filename';
    }
});
const filenameSearch = document.querySelector('[data-filename-search]');
if (filenameSearch) {
    const rows=[...document.querySelectorAll('[data-filename-row]')];
    const clear=document.querySelector('[data-filename-clear]');
    const count=document.querySelector('[data-filename-count]');
    const empty=document.querySelector('[data-filename-empty]');
    const normalize=text=>text.toLowerCase().replace(/[^a-z0-9]+/g,' ').trim();
    const filter=()=>{
        const terms=normalize(filenameSearch.value).split(/\s+/).filter(Boolean);let visible=0;
        rows.forEach(row=>{const text=normalize(row.dataset.filenameSearchText||'');row.hidden=!terms.every(term=>text.includes(term));if(!row.hidden)visible++;});
        if(clear)clear.hidden=!filenameSearch.value;
        if(count)count.textContent=`${visible} of ${rows.length} files`;
        if(empty)empty.hidden=visible>0||!terms.length;
    };
    filenameSearch.addEventListener('input',filter);
    clear?.addEventListener('click',()=>{filenameSearch.value='';filter();filenameSearch.focus();});
    window.addEventListener('pageshow',filter);
    filter();
}

const filenameProcessForms = [...document.querySelectorAll('[data-filename-process]')];
let filenameProcessing = false;
filenameProcessForms.forEach(form => form.addEventListener('submit', event => {
    if (filenameProcessing) { event.preventDefault(); return; }
    filenameProcessing = true;
    filenameProcessForms.forEach(other => { const button=other.querySelector('button');button.disabled=true;if(other===form){button.textContent='Processing…';other.setAttribute('aria-busy','true');} });
}));
window.addEventListener('pageshow',()=>{
    filenameProcessing=false;
    filenameProcessForms.forEach(form=>{form.removeAttribute('aria-busy');const button=form.querySelector('button');button.disabled=false;button.textContent='Process suggested name';});
});

const catalogSyncForms = [...document.querySelectorAll('[data-catalog-sync]')];
const catalogSyncButtons = [...document.querySelectorAll('[data-catalog-sync] button[type="submit"], button[form="artwork-scan-form"]')];
let catalogSyncPending = false;
catalogSyncForms.forEach(form => form.addEventListener('submit', event => {
    if (catalogSyncPending) { event.preventDefault(); return; }
    catalogSyncPending = true;
    catalogSyncForms.forEach(other => other.setAttribute('aria-busy', 'true'));
    catalogSyncButtons.forEach(button => { button.dataset.catalogSyncLabel = button.textContent; button.disabled = true; button.textContent = 'Syncing catalog…'; });
}));
window.addEventListener('pageshow', () => {
    catalogSyncPending = false;
    catalogSyncForms.forEach(form => form.removeAttribute('aria-busy'));
    catalogSyncButtons.forEach(button => {
        if (button.dataset.catalogSyncLabel !== undefined) { button.textContent = button.dataset.catalogSyncLabel; delete button.dataset.catalogSyncLabel; button.disabled = false; }
    });
});

const backlogForms = [...document.querySelectorAll('[data-backlog-form]')];
let backlogPending = false;
backlogForms.forEach(form => form.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
    if (backlogPending) { event.preventDefault(); return; }
    if (!window.confirm('Get the latest production backlog from Shopify and match its artwork from Google Drive? Print batches stay locked. Changed artwork needs approval again.')) {
        event.preventDefault();
        return;
    }
    backlogPending = true;
    backlogForms.forEach(other => {
        other.setAttribute('aria-busy', 'true');
        other.querySelectorAll('button[type="submit"]').forEach(button => {
            button.dataset.backlogLabel = button.textContent;
            button.disabled = true;
            button.textContent = 'Refreshing…';
        });
    });
}));
window.addEventListener('pageshow', () => {
    backlogPending = false;
    backlogForms.forEach(form => {
        form.removeAttribute('aria-busy');
        form.querySelectorAll('button[data-backlog-label]').forEach(button => {
            button.disabled = false;
            button.textContent = button.dataset.backlogLabel;
            delete button.dataset.backlogLabel;
        });
    });
});

const rematchForms = [...document.querySelectorAll('[data-rematch-form]')];
let rematchPending = false;
rematchForms.forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        if (rematchPending) { event.preventDefault(); return; }
        if (!window.confirm('Rematch all unbatched stock artwork from Google Drive? Changed artwork loses approval. Print batches remain locked.')) {
            event.preventDefault();
            return;
        }
        rematchPending = true;
        rematchForms.forEach((otherForm) => {
            otherForm.setAttribute('aria-busy', 'true');
            otherForm.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.dataset.rematchLabel = button.textContent;
                button.disabled = true;
                button.textContent = 'Rematching…';
            });
        });
    });
});
// A browser back/forward-cache restore must not leave the controls disabled.
window.addEventListener('pageshow', () => {
    rematchPending = false;
    rematchForms.forEach((form) => {
        form.removeAttribute('aria-busy');
        form.querySelectorAll('button[data-rematch-label]').forEach((button) => {
            button.disabled = false;
            button.textContent = button.dataset.rematchLabel;
            delete button.dataset.rematchLabel;
        });
    });
});

// Returning a queue entry never sends a print action.
const returnBatchForms = [...document.querySelectorAll('[data-return-batch]')];
returnBatchForms.forEach(form => form.addEventListener('submit', event => {
    const button = form.querySelector('button');
    if (button.disabled || !window.confirm('Return only this selected item to Artwork Review? Other queued items and their approvals stay unchanged.')) { event.preventDefault(); return; }
    returnBatchForms.filter(other => other.action === form.action || (form.dataset?.batchId && other.dataset?.batchId === form.dataset.batchId)).forEach(other => { const control = other.querySelector('button'); control.disabled = true; control.textContent = 'Returning…'; });
}));

// Bring action errors/setup notices into view after a redirect, even from a scrolled page.
const actionNotice = document.querySelector?.('.app-messages [role="alert"], .app-messages .flash-warning');
if (actionNotice) {
    actionNotice.setAttribute('tabindex', '-1');
    requestAnimationFrame(() => { actionNotice.scrollIntoView({block:'start',behavior:'smooth'}); actionNotice.focus({preventScroll:true}); });
}
