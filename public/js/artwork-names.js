const namingForm = document.querySelector('#artwork-naming-apply');
const namingSelected = () => [...(namingForm?.querySelectorAll('input[name="ids[]"]') || [])];
function namingCount() {
    const label = document.querySelector('[data-naming-selected]');
    if (label) label.textContent = `${namingSelected().filter(input => input.checked).length} selected`;
}
namingSelected().forEach(input => input.addEventListener('change', namingCount));
document.querySelector('[data-naming-select]')?.addEventListener('click', () => {
    let count = 0;
    namingSelected().forEach(input => { input.checked = !input.closest('tr').hidden && count++ < 500; });
    namingCount();
});
document.querySelector('[data-naming-search]')?.addEventListener('input', event => {
    const text = event.target.value.toLowerCase();
    document.querySelectorAll('[data-naming-row]').forEach(row => { row.hidden = !row.textContent.toLowerCase().includes(text); });
    // Keep the exact reviewed selection visible; filtering clears hidden selections.
    namingSelected().filter(input => input.closest('tr').hidden).forEach(input => { input.checked = false; });
    namingCount();
});
document.querySelector('[data-naming-catalog]')?.addEventListener('change', event => {
    const option = event.target.selectedOptions[0];
    document.querySelector('[data-naming-color]').value = option?.dataset.color || '';
    document.querySelector('[data-naming-side]').value = option?.dataset.type === 'double' ? 'front' : 'single';
});
let namingRunning = false;
namingForm?.addEventListener('submit', async event => {
    event.preventDefault();
    if (namingRunning) return;
    const selected = namingSelected().filter(input => input.checked);
    if (!selected.length) return;
    if (!window.confirm(`Rename ${selected.length} selected Drive files exactly as previewed? Existing file contents and print batches remain unchanged.`)) return;
    const progress = document.querySelector('[data-naming-progress]');
    const data = new FormData(namingForm);
    const token = data.get('plan');
    const csrf = data.get('_token');
    namingRunning = true;
    namingForm.querySelectorAll('button,input').forEach(input => { input.disabled = true; });
    const post = async (url, body) => {
        const response = await fetch(url, {method: 'POST', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body});
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Request failed. Check the audit before retrying.');
        return result;
    };
    try {
        progress.textContent = 'Validating current files, catalog and batch protection…';
        let result = await post(namingForm.action, data);
        namingForm.querySelector('[name="password"]').value = '';
        while (result.status === 'applying') {
            const next = new FormData(); next.set('plan', token); next.set('_token', csrf);
            result = await post(namingForm.dataset.stepUrl, next);
            progress.textContent = `${result.renamed} renamed · ${result.remaining} remaining. Keep this page open.`;
        }
        window.location.assign(result.url);
    } catch (error) {
        progress.textContent = `${error.message} Some files may already be renamed. Reload this plan to inspect its audit, then scan again.`;
        namingForm.querySelector('[name="password"]').value = '';
        // Do not enable a blind retry when the remote outcome might be uncertain.
    }
});

// A fresh check is read-only but may take time; avoid overlapping scans.
document.querySelector('[data-naming-scan]')?.closest('form')?.addEventListener('submit', event => {
    const button = event.target.querySelector('[data-naming-scan]');
    if (button.disabled) { event.preventDefault(); return; }
    button.disabled = true;
    button.textContent = 'Checking…';
});
