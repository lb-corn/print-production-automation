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
    if (open && !namingRunning) { open.disabled = !count || open.dataset?.namingEnabled === '0'; open.textContent = `Rename ${count} ready files`; }
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
const artworkColorCorrections = new Set();
function namingChoiceFilter(select, query, product = false) {
    if (!select) return;
    const terms = query.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim().split(/\s+/).filter(Boolean);
    const source = sourceChoice?.selectedOptions[0];
    const catalog = catalogChoice?.selectedOptions[0];
    let count = 0;
    [...select.options].forEach(option => {
        const label = (option.textContent || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ');
        const eligible = product || !catalog?.value || !catalog?.dataset.series || (catalog.dataset.scopeReady !== '0' && (!catalog.dataset.folderId || option.dataset.parentId === catalog.dataset.folderId) && option.dataset.series === catalog.dataset.series && option.dataset.protected !== '1' && (catalog.dataset.type !== 'double' || option.dataset.side !== 'single') && (catalog.dataset.type !== 'single' || option.dataset.side !== 'back') && (!catalog.dataset.color || !option.dataset.color || option.dataset.color === catalog.dataset.color || artworkColorCorrections.has(option.value)));
        option.hidden = !!option.value && (!eligible || !terms.every(term => label.includes(term)));
        // Searching hides rows; only scope/variant incompatibility disables submission.
        option.disabled = !!option.value && !eligible;
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
    // Shopify is primary: changing artwork must never clear the chosen product.
    const color = document.querySelector('[data-naming-color]'); if (color) color.value = source?.dataset.color || '';
    const side = document.querySelector('[data-naming-side]'); if (side) side.value = source?.dataset.side || '';
    const link = document.querySelector('[data-naming-original]'); if (link) { link.hidden = !source?.dataset.url; link.href = source?.dataset.url || '#'; }
    namingDetected();
    const needed = document.querySelector('[data-naming-side-needed]');
    if (needed) needed.hidden = true;
});
function namingMappingAttributes() {
    const option = catalogChoice?.selectedOptions[0];
    const source = sourceChoice?.selectedOptions[0];
    namingThumbnail('[data-naming-product-image]', option?.dataset.image);
    const color = document.querySelector('[data-naming-color]'); if (color) color.value = option?.dataset.color || source?.dataset.color || '';
    const side = document.querySelector('[data-naming-side]');
    if (side) side.value = option?.dataset.type === 'double' ? (['front','back'].includes(source?.dataset.side) ? source.dataset.side : '') : (option?.value ? 'single' : source?.dataset.side || '');
    const needed = document.querySelector('[data-naming-side-needed]'); if (needed) needed.hidden = !option?.value || !source?.value || !!side?.value;
    namingDetected();
}
sourceChoice?.addEventListener('change', namingMappingAttributes);
catalogChoice?.addEventListener('change', () => {
    if (sourceChoice) {
        sourceChoice.value = '';
        namingChoiceFilter(sourceChoice, '', false);
        sourceChoice.namingRefresh?.();
    }
    namingThumbnail('[data-naming-source-image]', '');
    const link = document.querySelector('[data-naming-original]'); if (link) link.hidden = true;
    namingMappingAttributes();
});
function namingCatalogFilter() {
    const query = document.querySelector('[data-catalog-search]')?.value || '';
    const status = document.querySelector('[data-catalog-status]')?.value || 'attention';
    const terms = query.toLowerCase().trim().split(/\s+/).filter(Boolean); let count = 0;
    document.querySelectorAll('[data-catalog-row]').forEach(row => {
        const state = row.dataset.artworkStatus;
        row.hidden = !(terms.every(term => row.textContent.toLowerCase().includes(term)) && (status === 'all' || (status === 'attention' ? state !== 'matched' : state === status)));
        if (!row.hidden) count++;
    });
    const empty = document.querySelector('[data-catalog-empty]'); if (empty) empty.hidden = count > 0;
    const message = document.querySelector('[data-catalog-empty-message]'); if (message) message.textContent = terms.length ? 'No Shopify products match this search. Search by product name or SKU.' : 'No products in this status. View all Shopify products.';
    const clear = document.querySelector('[data-catalog-clear]'); if (clear) clear.textContent = terms.length ? 'Clear search' : 'Show all products';
}
document.querySelector('[data-catalog-search]')?.addEventListener('input', namingCatalogFilter);
document.querySelector('[data-catalog-status]')?.addEventListener('change', namingCatalogFilter);
document.querySelector('[data-catalog-clear]')?.addEventListener('click', () => {
    const search = document.querySelector('[data-catalog-search]'); const status = document.querySelector('[data-catalog-status]');
    if (search?.value) search.value = ''; else if (status) status.value = 'all';
    namingCatalogFilter(); search?.focus();
});
function openNamingConfirmation() {
    const count = namingSelected().filter(input => input.checked).length;
    if (!count || namingRunning || document.querySelector('[data-naming-open]')?.dataset.namingEnabled === '0') return;
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
            error.preflightRejected = response.status >= 400 && response.status < 500;
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
        if (!started && (error.passwordError || error.preflightRejected)) {
            namingRunning = false;
            namingForm.querySelectorAll('button,input').forEach(input => { input.disabled = false; });
            progress.textContent = `${error.message} No files were renamed.`;
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
    search.placeholder = product ? 'Search Shopify products…' : 'Search Drive artwork…';
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
if (!document.querySelector('[data-artwork-match-dialog]')) {
    namingSearchableSelect(sourceChoice);
    namingSearchableSelect(catalogChoice, true);
}


// One worklist, with a focused visual choice for the selected Shopify variant.
const artworkMatchDialog = document.querySelector('[data-artwork-match-dialog]');
let artworkPair = {front:'', back:''};
let artworkPairTarget = 'front';
function namingIsPair() { return catalogChoice?.selectedOptions[0]?.dataset.type === 'double' && !!document.querySelector('[data-match-pair]'); }
function namingMatchSelection() {
    const option = sourceChoice?.selectedOptions[0];
    const selection = document.querySelector('[data-match-selection]'); if (selection) selection.hidden = !option?.value;
    const name = document.querySelector('[data-match-selection-name]'); if (name) name.textContent = option?.value ? option.textContent : '';
    const confirm = document.querySelector('[data-match-confirm]');
    if (confirm) {
        confirm.disabled = namingIsPair() ? !(artworkPair.front && artworkPair.back && artworkPair.front !== artworkPair.back) : (!sourceChoice?.value || !catalogChoice?.value || !document.querySelector('[data-naming-side]')?.value);
        confirm.textContent = namingIsPair() ? 'Confirm front + back' : 'Confirm this artwork';
    }
    if (namingIsPair()) {
        const needed = document.querySelector('[data-naming-side-needed]'); if (needed) needed.hidden = true;
        for (const side of ['front','back']) {
            const file = [...sourceChoice.options].find(option => option.value === artworkPair[side]);
            document.querySelector('[data-match-' + side + '-name]').textContent = file?.textContent || 'Choose ' + side + ' artwork';
            document.querySelector('[data-match-' + side + '-input]').value = artworkPair[side];
        }
        document.querySelectorAll('[data-match-side-target]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.matchSideTarget === artworkPairTarget)));
    }
    const selected = namingIsPair() ? Object.values(artworkPair) : [sourceChoice?.value];
    const correctionNeeded = selected.some(id => artworkColorCorrections.has(id));
    const correction = document.querySelector('[data-match-color-confirm]'); if (correction) correction.hidden = !correctionNeeded;
    const checkbox = document.querySelector('[data-match-color-checkbox]');
    if (checkbox) { checkbox.disabled = !correctionNeeded; checkbox.required = correctionNeeded; if (!correctionNeeded) checkbox.checked = false; }
    if (confirm && correctionNeeded && !checkbox?.checked) confirm.disabled = true;
    document.querySelectorAll('[data-artwork-file]').forEach(button => button.setAttribute('aria-pressed', String(namingIsPair() ? Object.values(artworkPair).includes(button.dataset.artworkFile) : button.dataset.artworkFile === sourceChoice?.value)));
}
function namingArtworkGallery() {
    const grid = document.querySelector('[data-artwork-candidates]'); if (!grid) return;
    grid.replaceChildren();
    const options = [...sourceChoice.options].filter(option => option.value && !option.hidden && !option.disabled);
    namingArtworkExclusions();
    options.forEach(option => {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'artwork-file-choice';
        button.dataset.artworkFile = option.value; button.setAttribute('aria-pressed', String(namingIsPair() ? Object.values(artworkPair).includes(option.value) : option.value === sourceChoice.value));
        const image = document.createElement('img'); image.alt = ''; image.loading = 'lazy';
        if (option.dataset.thumbnail?.startsWith('https://')) image.src = option.dataset.thumbnail;
        image.onerror = () => { image.hidden = true; };
        const text = document.createElement('span'); text.textContent = option.textContent;
        const detail = document.createElement('small'); detail.textContent = [option.dataset.side || 'Side not named', option.dataset.color || 'No color label', option.dataset.linked ? 'Linked: ' + option.dataset.linked : 'Not linked'].join(' · ');
        const caption = document.createElement('span'); caption.append(text, detail); button.append(image, caption);
        button.addEventListener('click', () => namingChooseArtwork(option));
        grid.append(button);
    });
    const empty = document.querySelector('[data-artwork-candidate-empty]'); if (empty) { empty.hidden = options.length > 0; empty.textContent = catalogChoice?.selectedOptions[0]?.dataset.scopeReady === '0' ? 'The series folder is missing or ambiguous. Fix the folder location, then Check files.' : 'No eligible artwork found here. Upload the production file to the folder above, then Check files.'; }
    const count = document.querySelector('[data-match-candidate-count]'); if (count) count.textContent = options.length + ' eligible files · choose by image, not filename alone';
}
if (artworkMatchDialog && sourceChoice) {
    artworkMatchDialog.addEventListener('cancel',event=>{if(document.querySelector('[data-artwork-match-form]')?.dataset.submitting==='1')event.preventDefault();});
    sourceChoice.namingRefresh = namingArtworkGallery;
    document.querySelectorAll('[data-match-product]').forEach(button => button.addEventListener('click', () => {
        const form=document.querySelector('[data-artwork-match-form]'); if(form){delete form.dataset.saved;delete form.dataset.savedUrl;delete form.dataset.submitting;}
        const feedback=document.querySelector('[data-match-feedback]');if(feedback)feedback.hidden=true;
        const orders=document.querySelector('[data-match-review-orders]');if(orders)orders.hidden=true;
        const help = document.querySelector('.artwork-find-help'); if (help) help.open = false;
        artworkColorCorrections.clear();
        const checkbox = document.querySelector('[data-match-color-checkbox]'); if (checkbox) checkbox.checked = false;
        catalogChoice.value = button.dataset.matchProduct;
        const option = catalogChoice.selectedOptions[0];
        const search = document.querySelector('[data-naming-source-search]'); if (search) search.value = '';
        catalogChoice.dispatchEvent(new Event('change', {bubbles:true}));
        document.querySelector('[data-match-title]').textContent = option.textContent;
        const warning = document.querySelector('[data-match-sku-warning]'); if (warning) warning.hidden = option.dataset.skuConflict !== '1';
        document.querySelector('[data-match-folder]').textContent = option.dataset.folder || '';
        const link = document.querySelector('[data-match-folder-link]'); link.hidden = !option.dataset.folderUrl; link.href = option.dataset.folderUrl || '#';
        namingMatchSelection(); artworkMatchDialog.showModal(); search?.focus();
    }));
    document.querySelector('[data-match-close]')?.addEventListener('click', () => {
        const form=document.querySelector('[data-artwork-match-form]');
        if(form?.dataset.submitting==='1')return;
        if(form?.dataset.savedUrl)window.location.assign(form.dataset.savedUrl);else artworkMatchDialog.close();
    });
    sourceChoice.addEventListener('change', namingMatchSelection);
    document.querySelector('[data-naming-side]')?.addEventListener('change', namingMatchSelection);
    document.querySelector('[data-artwork-match-form]')?.addEventListener('submit', event => {
        event.preventDefault();
        const form = document.querySelector('[data-artwork-match-form]');
        if (form.dataset.submitting === '1') { event.preventDefault(); return; }
        namingMatchSelection(); const button = document.querySelector('[data-match-confirm]');
        if (button.disabled) { event.preventDefault(); return; }
        if(form.dataset.saved==='1')return;
        namingSaveChoice(form);
    });
}
namingCatalogFilter();

async function namingSaveChoice(form) {
    const button=document.querySelector('[data-match-confirm]');
    const feedback=document.querySelector('[data-match-feedback]');
    const orders=document.querySelector('[data-match-review-orders]');
    const controller=new AbortController();const timer=setTimeout(()=>controller.abort(),45000);
    form.dataset.submitting='1';button.disabled=true;button.textContent='Saving artwork…';
    if(feedback){feedback.hidden=false;feedback.textContent='Saving your choice and checking current Drive files. This can take a moment.';}
    try {
        const response=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{Accept:'application/json'},signal:controller.signal});
        const data=await response.json();
        if(!response.ok)throw new Error(data.errors?Object.values(data.errors).flat().join(' '):(data.message||'Artwork could not be saved. Check the selection.'));
        if(!['ok','warning'].includes(data.status))throw new Error('The save result could not be verified. Check Artwork Verification before trying again.');
        form.dataset.saved='1';form.dataset.savedUrl=data.url;
        if(feedback)feedback.textContent=data.message;
        if(orders)orders.hidden=false;
        button.textContent=data.status==='ok'?'Artwork saved':'Choice saved · refresh needed';
    } catch(error) {
        if(feedback)feedback.textContent=error.name==='AbortError'||error instanceof TypeError||error instanceof SyntaxError?'The save result is uncertain because the connection timed out or failed. Check Artwork Verification before submitting again.':error.message;
        if(orders)orders.hidden=false;
    } finally {
        clearTimeout(timer);delete form.dataset.submitting;
        if(form.dataset.saved!=='1')namingMatchSelection();
    }
}

function namingArtworkExclusions() {
    const grid = document.querySelector('[data-artwork-excluded]'); if (!grid) return;
    const catalog = catalogChoice.selectedOptions[0]; grid.replaceChildren();
    const query = document.querySelector('[data-naming-source-search]')?.value || '';
    const terms = query.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim().split(/\s+/).filter(Boolean);
    const files = [...sourceChoice.options].filter(option => terms.every(term => option.textContent.toLowerCase().replace(/[^a-z0-9]+/g, ' ').includes(term)) && catalog.dataset.scopeReady !== '0' && option.value && option.dataset.series === catalog.dataset.series && (!catalog.dataset.folderId || option.dataset.parentId === catalog.dataset.folderId) && option.disabled);
    const help = document.querySelector('.artwork-find-help'); if (help && terms.length && files.length) help.open = true;
    files.forEach(option => {
        const link = document.createElement('div'); link.className = 'artwork-excluded-file';
        const image = document.createElement('img'); image.alt = ''; image.loading = 'lazy';
        if (option.dataset.thumbnail?.startsWith('https://')) image.src = option.dataset.thumbnail;
        image.onerror = () => { image.hidden = true; };
        const text = document.createElement('span'); text.textContent = option.textContent;
        const sideConflict=(catalog.dataset.type==='single' && option.dataset.side==='back') || (catalog.dataset.type==='double' && option.dataset.side==='single');
        const reason = document.createElement('small'); reason.textContent = option.dataset.protected === '1' ? 'Protected: used by a print batch; cannot rename' : (catalog.dataset.type==='single' && option.dataset.side==='back') ? 'Filename says BACK; this product needs single-sided production artwork. Choose the front file, or verify and correct the filename in Drive, then sync the catalog.' : sideConflict ? 'Filename says SINGLE; this variant needs FRONT and BACK' : 'Filename color: ' + (option.dataset.color || 'not named') + ' · Shopify color: ' + (catalog.dataset.color || 'not named');
        text.append(reason); link.append(image, text);
        const actions = document.createElement('span'); actions.className = 'artwork-excluded-actions';
        const original = document.createElement('a'); original.textContent = 'Open image ↗'; original.target = '_blank'; original.rel = 'noopener noreferrer';
        if (option.dataset.url?.startsWith('https://drive.google.com/')) original.href = option.dataset.url;
        actions.append(original);
        if (option.dataset.protected !== '1' && !sideConflict && catalog.dataset.color && option.dataset.color && option.dataset.color !== catalog.dataset.color) {
            const use = document.createElement('button'); use.type = 'button'; use.className = 'chip'; use.textContent = 'Use & correct color'; use.dataset.artworkFile = option.value; use.setAttribute('aria-pressed', String(sourceChoice.value === option.value));
            use.addEventListener('click', () => namingChooseArtwork(option, true)); actions.append(use);
        }
        link.append(actions); grid.append(link);
    });
    const count = document.querySelector('[data-match-excluded-count]'); if (count) count.textContent = files.length ? files.length + ' excluded in this folder' : '';
}

function namingSetupPair() {
    const pair = document.querySelector('[data-match-pair]'); if (!pair) return;
    const double = catalogChoice?.selectedOptions[0]?.dataset.type === 'double';
    artworkPair = {front:'',back:''}; artworkPairTarget = 'front'; pair.hidden = !double;
    const error = document.querySelector('[data-match-pair-error]'); if (error) error.hidden = true;
    for (const selector of ['[data-match-pair-mode]','[data-match-front-input]','[data-match-back-input]']) {
        const input = document.querySelector(selector); if (input) { input.disabled = !double; if (selector !== '[data-match-pair-mode]') input.value = ''; }
    }
    sourceChoice.disabled = double;
    const side = document.querySelector('[data-naming-side]'); if (side) side.disabled = double;
    namingMatchSelection();
}
catalogChoice?.addEventListener('change', namingSetupPair);
document.querySelectorAll('[data-match-side-target]').forEach(button => button.addEventListener('click', () => { artworkPairTarget = button.dataset.matchSideTarget; namingMatchSelection(); }));

function namingChooseArtwork(option, correctColor = false) {
    const checkbox = document.querySelector('[data-match-color-checkbox]');
    if (checkbox && (correctColor || sourceChoice.value !== option.value)) checkbox.checked = false;
    if (correctColor) { artworkColorCorrections.add(option.value); option.disabled = false; }
    if (namingIsPair()) {
        const side = ['front','back'].includes(option.dataset.side) ? option.dataset.side : artworkPairTarget;
        const other = side === 'front' ? 'back' : 'front';
        if (artworkPair[other] === option.value) return;
        const otherFile = [...sourceChoice.options].find(file => file.value === artworkPair[other]);
        const error = document.querySelector('[data-match-pair-error]');
        const reviewedLabel = catalogChoice.selectedOptions[0]?.dataset.color && (artworkColorCorrections.has(option.value) || artworkColorCorrections.has(otherFile?.value));
        if (option.dataset.color && otherFile?.dataset.color && option.dataset.color !== otherFile.dataset.color && !reviewedLabel) {
            if (error) { error.hidden = false; error.textContent = 'Front and back have different filename colors. Choose matching artwork.'; }
            return;
        }
        if (error) error.hidden = true;
        artworkPair[side] = option.value;
        if (!artworkPair[other]) artworkPairTarget = other;
    }
    sourceChoice.value = option.value;
    sourceChoice.dispatchEvent(new Event('change', {bubbles:true}));
    namingMatchSelection();
}

document.querySelector('[data-match-color-checkbox]')?.addEventListener('change', namingMatchSelection);
