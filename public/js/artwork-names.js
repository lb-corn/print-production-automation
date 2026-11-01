const namingForm = document.querySelector('#artwork-naming-apply');
const namingDialog = document.querySelector('[data-naming-dialog]');
const namingSearch = document.querySelector('[data-naming-search]');
const namingSelected = () => [...(namingForm?.querySelectorAll('input[name="ids[]"]') || [])];
let namingRunning = false;
let namingMode = 'ready';
if (namingSearch) namingSearch.value = '';
function namingCount() {
    const count = namingSelected().filter(input => input.checked).length;
    const label = document.querySelector('[data-naming-selected]');
    if (label) label.textContent = `${count} ready files selected`;
    const open = document.querySelector('[data-naming-open]');
    if (open && !namingRunning) { open.disabled = !count; open.textContent = `Rename ${count} ready files`; }
    const select = document.querySelector('[data-naming-select]');
    if (select) select.hidden = count >= Math.min(500, namingSelected().length);
}
function namingFilter() {
    const text = namingSearch?.value.toLowerCase() || '';
    let visible = 0;
    document.querySelectorAll('[data-naming-row]').forEach(row => {
        const status = row.dataset.namingStatus;
        const mode = namingMode === 'all' || (namingMode === 'review' ? ['review','conflict'].includes(status) : status === 'ready');
        row.hidden = !(mode && row.textContent.toLowerCase().includes(text));
        if (!row.hidden) visible++;
    });
    const empty = document.querySelector('[data-naming-empty]');
    if (empty) empty.hidden = visible > 0;
}
namingSelected().forEach(input => input.addEventListener('change', namingCount));
document.querySelector('[data-naming-select]')?.addEventListener('click', () => {
    namingSelected().forEach((input,index) => { input.checked = index < 500; });
    namingCount();
});
namingSearch?.addEventListener('input', () => { namingFilter(); });
document.querySelectorAll('[data-naming-mode]').forEach(button => button.addEventListener('click', () => {
    namingMode = button.dataset.namingMode;
    document.querySelectorAll('[data-naming-mode]').forEach(other => other.classList.toggle('active', other === button));
    namingFilter();
}));
document.querySelector('[data-naming-clear]')?.addEventListener('click', () => {
    if (namingSearch) namingSearch.value = '';
    namingMode = 'all'; namingFilter();
});
const sourceChoice = document.querySelector('[data-naming-source]');
const catalogChoice = document.querySelector('[data-naming-catalog]');
sourceChoice?.addEventListener('change', () => {
    const source = sourceChoice.selectedOptions[0];
    if (catalogChoice) {
        catalogChoice.value = '';
        [...catalogChoice.options].forEach(option => { option.hidden = !!option.value && !!source?.dataset.series && option.dataset.series !== source.dataset.series; option.disabled = option.hidden; });
    }
    const color = document.querySelector('[data-naming-color]'); if (color) color.value = source?.dataset.color || '';
    const side = document.querySelector('[data-naming-side]'); if (side) side.value = source?.dataset.side || '';
    const link = document.querySelector('[data-naming-original]'); if (link) { link.hidden = !source?.dataset.url; link.href = source?.dataset.url || '#'; }
});
catalogChoice?.addEventListener('change', () => {
    const option = catalogChoice.selectedOptions[0];
    document.querySelector('[data-naming-color]').value = option?.dataset.color || sourceChoice?.selectedOptions[0]?.dataset.color || '';
    const sourceSide = sourceChoice?.selectedOptions[0]?.dataset.side;
    document.querySelector('[data-naming-side]').value = option?.dataset.type === 'double' ? (['front','back'].includes(sourceSide) ? sourceSide : '') : 'single';
});
function openNamingConfirmation() {
    const count = namingSelected().filter(input => input.checked).length;
    if (!count || namingRunning) return;
    const label = document.querySelector('[data-naming-confirm-count]'); if (label) label.textContent = count;
    const password = namingForm.querySelector('[name="password"]'); password.disabled = false; password.value = '';
    namingDialog?.showModal();
}
document.querySelector('[data-naming-open]')?.addEventListener('click', openNamingConfirmation);
document.querySelector('[data-naming-cancel]')?.addEventListener('click', () => {
    if (namingRunning) return;
    const password = namingForm.querySelector('[name="password"]'); password.value = ''; password.disabled = true;
    namingDialog?.close();
});
namingDialog?.addEventListener('cancel', event => {
    if (namingRunning) { event.preventDefault(); return; }
    const password = namingForm.querySelector('[name="password"]'); password.value = ''; password.disabled = true;
});
namingForm?.addEventListener('submit', async event => {
    event.preventDefault();
    if (namingRunning) return;
    if (namingDialog && !namingDialog.open) { openNamingConfirmation(); return; }
    if (!namingForm.reportValidity()) return;
    const selected = namingSelected().filter(input => input.checked);
    if (!selected.length) return;
    const progress = document.querySelector('[data-naming-progress]');
    const data = new FormData(namingForm);
    const token = data.get('plan'); const csrf = data.get('_token');
    namingRunning = true;
    namingForm.querySelectorAll('button,input').forEach(input => { input.disabled = true; });
    const post = async (url, body) => {
        const response = await fetch(url, {method: 'POST', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body});
        const result = await response.json();
        if (!response.ok) {
            const message = result.errors ? Object.values(result.errors).flat()[0] : result.message;
            const error = new Error(message || 'Request failed. Check the audit before retrying.');
            error.passwordError = !!result.errors?.password;
            throw error;
        }
        return result;
    };
    let started = false;
    try {
        progress.textContent = 'Checking current files and batch protection…';
        let result = await post(namingForm.action, data); started = true;
        namingForm.querySelector('[name="password"]').value = '';
        while (result.status === 'applying') {
            const next = new FormData(); next.set('plan', token); next.set('_token', csrf);
            result = await post(namingForm.dataset.stepUrl, next);
            progress.textContent = `${result.renamed} renamed · ${result.remaining} remaining. Keep this page open.`;
        }
        window.location.assign(result.url);
    } catch (error) {
        namingForm.querySelector('[name="password"]').value = '';
        if (!started && error.passwordError) {
            namingRunning = false;
            namingForm.querySelectorAll('button,input').forEach(input => { input.disabled = false; });
            progress.textContent = error.message;
        } else {
            progress.textContent = `${error.message} Some files may already be renamed. Reload to inspect the audit, then check files again.`;
        }
    }
});
document.querySelector('[data-naming-scan]')?.closest('form')?.addEventListener('submit', event => {
    const button = event.target.querySelector('[data-naming-scan]');
    if (button.disabled) { event.preventDefault(); return; }
    button.disabled = true; button.textContent = 'Checking…';
});
window.addEventListener('pageshow', event => { if (event.persisted && namingRunning) window.location.reload(); });
namingCount(); namingFilter();
