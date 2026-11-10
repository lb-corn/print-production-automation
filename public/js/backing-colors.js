(() => {
    const sections = [...document.querySelectorAll('[data-backing-review]')];
    let queue = Promise.resolve();
    sections.forEach(section => {
        const button = section.querySelector('[data-backing-compare]');
        if (!button) return;
        const message = section.querySelector('[data-backing-message]');
        const select = section.querySelector('[data-backing-color]');
        let loaded = false, busy = false, swatches = [];
        const updateSwatch = () => {
            const image = section.querySelector('[data-backing-selected-image]');
            section.querySelectorAll('.backing-swatch').forEach(tile => {const selected = tile.dataset.color === select?.value;tile.classList.toggle('selected', selected);tile.setAttribute('aria-pressed',String(selected));});
            const current = swatches.find(swatch => swatch.color === select?.value);
            if (current?.image) {image.src = current.image;image.alt = current.color + ' backing swatch';image.hidden = false;} else image.hidden = true;
        };
        if (section.dataset.locked === 'true') return;
        const compare = async () => {
            if (busy) return;
            busy = true; button.disabled = true; button.textContent = 'Comparing…';
            try {
                const response = await fetch(section.dataset.suggestUrl, {headers:{Accept:'application/json'},credentials:'same-origin'});
                if (!response.ok || response.redirected) throw Error('Comparison unavailable');
                const result = await response.json();
                message.textContent = result.suggested ? `Suggested: ${result.suggested} · Approve saves this color and artwork.` : 'Choose a sheet color, then Approve.';
                const context = section.querySelector('[data-backing-context]');
                if (context && context.value !== result.context) {
                    message.textContent = 'Settings changed · reload before approving.';
                    return;
                }
                const list = section.querySelector('[data-backing-swatches]');
                list.replaceChildren();
                swatches = result.swatches;
                result.swatches.forEach(swatch => {
                    const card = document.createElement('button');card.type = 'button';card.className = 'backing-swatch';card.dataset.color = swatch.color;
                    card.disabled = !select || select.disabled;card.setAttribute('aria-label', `Select sheet backing color ${swatch.color}`);
                    if (swatch.image) {const image = document.createElement('img');image.src = swatch.image;image.alt = `${swatch.color} backing swatch`;image.loading = 'lazy';card.append(image);}
                    const label = document.createElement('span');label.textContent = swatch.color;card.append(label);
                    if (result.suggested === swatch.color) {const hint = document.createElement('small');hint.textContent = 'Suggested';card.append(hint);card.classList.add('suggested');}
                    card.addEventListener('click', () => {select.value = swatch.color;select.dispatchEvent(new Event('change',{bubbles:true}));});
                    list.append(card);
                });
                if (select && !select.value && result.suggested) select.value = result.suggested;
                updateSwatch();
                const source = section.closest('.job-card')?.querySelector('.backing-source-image');
                const crop = source?.querySelector('[data-backing-crop]');
                const sourceImage = source?.querySelector('img');
                if (crop && result.crop && sourceImage) {
                    const alignCrop = () => {
                        if (!sourceImage.naturalWidth || !sourceImage.naturalHeight) return;
                        const width = sourceImage.clientWidth, height = sourceImage.clientHeight;
                        const scale = Math.min(width / sourceImage.naturalWidth, height / sourceImage.naturalHeight);
                        const imageWidth = sourceImage.naturalWidth * scale, imageHeight = sourceImage.naturalHeight * scale;
                        const bounds = {left:(width-imageWidth)/2 + result.crop.x*imageWidth,top:(height-imageHeight)/2 + result.crop.y*imageHeight,width:result.crop.width*imageWidth,height:result.crop.height*imageHeight};
                        for (const [name,value] of Object.entries(bounds)) crop.style[name] = `${value}px`;
                        crop.hidden = false;
                    };
                    alignCrop();sourceImage.addEventListener('load',alignCrop,{once:true});
                    if ('ResizeObserver' in window) new ResizeObserver(alignCrop).observe(sourceImage);
                }
                loaded = true;
            } catch (_) {
                message.textContent = 'No automatic match · choose a sheet color, then Approve.';
            } finally {busy = false;button.disabled = section.dataset.locked === 'true';button.textContent = 'Retry comparison';}
        };
        const enqueue = () => {queue = queue.then(compare, compare);};
        button.addEventListener('click', enqueue);
        select?.addEventListener('change', () => {updateSwatch();message.textContent = 'Selected: ' + select.value + ' · saved with Approve.';});
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(entries => {
                if (!loaded && entries.some(entry => entry.isIntersecting)) {observer.disconnect();enqueue();}
            }, {threshold:.1});
            observer.observe(section);
        }
    });
})();
