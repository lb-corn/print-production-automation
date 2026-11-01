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
function namingChoiceFilter(select, query, product = false) {
    if (!select) return;
    const terms = query.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim().split(/\s+/).filter(Boolean);
    const source = sourceChoice?.selectedOptions[0];
    let count = 0;
    [...select.options].forEach(option => {
        const label = (option.textContent || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ');
        const eligible = !product || !source?.dataset.series || (option.dataset.series === source.dataset.series && (!source.dataset.color || option.dataset.color === source.dataset.color));
        option.hidden = !!option.value && (!eligible || !terms.every(term => label.includes(term)));
        option.disabled = option.hidden;
        if (option.value && !option.hidden) count++;
    });
    const empty = document.querySelector(product ? '[data-naming-product-empty]' : '[data-naming-source-empty]');
    if (empty) empty.hidden = count > 0;
    select.namingRefresh?.();
}
document.querySelector('[data-naming-source-search]')?.addEventListener('input', event => namingChoiceFilter(sourceChoice, event.target.value));
document.querySelector('[data-naming-product-search]')?.addEventListener('input', event => namingChoiceFilter(catalogChoice, event.target.value, true));
function namingThumbnail(selector, url) {
    const image = document.querySelector(selector);
    if (!image) return;
    image.hidden = !url;
    if (url && url.startsWith('https://')) image.src = url; else image.removeAttribute('src');
    image.onerror = () => { image.hidden = true; };
}
function namingDetected() {
    const label = document.querySelector('[data-naming-detected]');
    const source = sourceChoice?.selectedOptions[0];
    if (label) label.textContent = [source?.dataset.series, document.querySelector('[data-naming-color]')?.value, document.querySelector('[data-naming-side]')?.value || 'Choose print side under details'].filter(Boolean).join(' · ');
}
sourceChoice?.addEventListener('change', () => {
    const source = sourceChoice.selectedOptions[0];
    namingThumbnail('[data-naming-source-image]', source?.dataset.thumbnail);
    namingThumbnail('[data-naming-product-image]', '');
    if (catalogChoice) {
        catalogChoice.value = '';
        namingChoiceFilter(catalogChoice, document.querySelector('[data-naming-product-search]')?.value || '', true);
    }
    const color = document.querySelector('[data-naming-color]'); if (color) color.value = source?.dataset.color || '';
    const side = document.querySelector('[data-naming-side]'); if (side) side.value = source?.dataset.side || '';
    const link = document.querySelector('[data-naming-original]'); if (link) { link.hidden = !source?.dataset.url; link.href = source?.dataset.url || '#'; }
    namingDetected();
    const needed = document.querySelector('[data-naming-side-needed]');
    if (needed) needed.hidden = true;
});
catalogChoice?.addEventListener('change', () => {
    const option = catalogChoice.selectedOptions[0];
    namingThumbnail('[data-naming-product-image]', option?.dataset.image);
    document.querySelector('[data-naming-color]').value = option?.dataset.color || sourceChoice?.selectedOptions[0]?.dataset.color || '';
    const sourceSide = sourceChoice?.selectedOptions[0]?.dataset.side;
    document.querySelector('[data-naming-side]').value = option?.dataset.type === 'double' ? (['front','back'].includes(sourceSide) ? sourceSide : '') : 'single';
    const needed = document.querySelector('[data-naming-side-needed]');
    if (needed) needed.hidden = !!document.querySelector('[data-naming-side]').value;
    namingDetected();
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

// Progressive enhancement: the native select remains the submitted source of truth.
function namingSearchableSelect(select, product = false) {
    if (!select || !document.createElement) return;
    const wrapper = document.createElement('span'); wrapper.className = 'naming-picker';
    const trigger = document.createElement('button'); trigger.type = 'button'; trigger.className = 'naming-picker-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox'); trigger.setAttribute('aria-expanded', 'false');
    const panel = document.createElement('span'); panel.className = 'naming-picker-panel'; panel.hidden = true;
    const search = document.createElement('input'); search.type = 'search'; search.autocomplete = 'off';
    search.placeholder = product ? 'Search products…' : 'Search designs…';
    search.setAttribute('aria-label', product ? 'Search matching products' : 'Search designs');
    search.setAttribute(product ? 'data-naming-product-search' : 'data-naming-source-search', '');
    search.setAttribute('role', 'combobox'); search.setAttribute('aria-autocomplete', 'list'); search.setAttribute('aria-expanded', 'false');
    const list = document.createElement('span'); list.className = 'naming-picker-list'; list.id = product ? 'naming-product-options' : 'naming-design-options';
    list.setAttribute('role', 'listbox'); list.setAttribute('aria-label', search.getAttribute('aria-label'));
    search.setAttribute('aria-controls', list.id); trigger.setAttribute('aria-controls', list.id);
    let active = -1, options = [];
    function close(focus = false) {
        panel.hidden = true; trigger.setAttribute('aria-expanded', 'false'); search.setAttribute('aria-expanded', 'false');
        search.removeAttribute('aria-activedescendant'); if (focus) trigger.focus();
    }
    function highlight(index) {
        active = index;
        [...list.children].forEach((node, i) => node.classList.toggle('active', i === active));
        const node = list.children[active];
        if (node && options.length) { search.setAttribute('aria-activedescendant', node.id); node.scrollIntoView({block:'nearest'}); }
    }
    function choose(option) { select.value = option.value; select.dispatchEvent(new Event('change', {bubbles:true})); close(true); }
    function render() {
        trigger.textContent = (select.selectedOptions[0] || select.options[0])?.textContent || 'Choose';
        list.replaceChildren(); active = -1; search.removeAttribute('aria-activedescendant');
        options = [...select.options].filter(option => option.value && !option.hidden && !option.disabled);
        options.forEach((option, index) => {
            const item = document.createElement('button'); item.type = 'button'; item.tabIndex = -1; item.id = list.id + '-' + index; item.setAttribute('role','option');
            item.setAttribute('aria-selected', String(option.value === select.value)); item.textContent = option.textContent;
            // Keep focus in search: a blur must not close the panel before click fires.
            item.addEventListener('pointerdown', event => event.preventDefault());
            item.addEventListener('click', event => { event.preventDefault(); choose(option); }); list.append(item);
        });
        if (!options.length) { const empty = document.createElement('span'); empty.className = 'naming-picker-empty'; empty.textContent = 'No matches found'; list.append(empty); }
    }
    function open() {
        search.value = ''; namingChoiceFilter(select, '', product); panel.hidden = false;
        trigger.setAttribute('aria-expanded','true'); search.setAttribute('aria-expanded','true'); search.focus();
    }
    trigger.addEventListener('click', () => panel.hidden ? open() : close());
    search.addEventListener('input', () => namingChoiceFilter(select, search.value, product));
    search.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); close(true); }
        if (['ArrowDown','ArrowUp'].includes(event.key)) {
            event.preventDefault(); if (options.length) highlight((active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length);
        }
        if (event.key === 'Enter') { event.preventDefault(); if (active >= 0 && options[active]) choose(options[active]); }
    });
    wrapper.addEventListener('focusout', event => { if (!wrapper.contains(event.relatedTarget)) close(); });
    document.addEventListener('click', event => { if (!wrapper.contains(event.target)) close(); });
    select.addEventListener('change', render);
    select.addEventListener('invalid', event => { event.preventDefault(); open(); });
    select.before(wrapper); wrapper.append(trigger, panel); panel.append(search, list);
    select.hidden = true; select.namingRefresh = render; render();
}
namingSearchableSelect(sourceChoice);
namingSearchableSelect(catalogChoice, true);
