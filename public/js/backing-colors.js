(() => {
    const sections = [...document.querySelectorAll('[data-backing-review]')];
    const queue=[];let active=0;
    const drain=()=>{while(active<2 && queue.length){const task=queue.shift();active++;Promise.resolve().then(task).finally(()=>{active--;drain();});}};
    sections.forEach(section => {
        const button = section.querySelector('[data-backing-compare]');
        if (!button) return;
        const message = section.querySelector('[data-backing-message]');
        const select = section.querySelector('[data-backing-color]');
        const fpdInput = section.querySelector('[data-fpd-backside]');
        const fpdButton = section.querySelector('[data-fpd-compare]');
        let busy = false, queued = false, changed = false;
        let swatches=[...section.querySelectorAll('.backing-swatch')].map(tile=>({color:tile.dataset.color,image:tile.querySelector('img')?.getAttribute('src')}));
        const updateSwatch = () => {
            const image = section.querySelector('[data-backing-selected-image]');
            section.querySelectorAll('.backing-swatch').forEach(tile => {const selected = tile.dataset.color === select?.value;tile.classList.toggle('selected', selected);tile.setAttribute('aria-pressed',String(selected));});
            const current = swatches.find(swatch => swatch.color === select?.value);
            if (current?.image) {image.src = current.image;image.alt = current.color + ' backing swatch';image.hidden = false;} else image.hidden = true;
        };
        const bindTile=card=>card.addEventListener('click',()=>{if(!select || select.disabled)return;select.value=card.dataset.color;select.dispatchEvent(new Event('change',{bubbles:true}));});
        section.querySelectorAll('.backing-swatch').forEach(bindTile);
        section.addEventListener('error',event=>{if(event.target instanceof HTMLImageElement)event.target.hidden=true;},true);
        updateSwatch();
        if (section.dataset.locked === 'true') return;
        const compare = async () => {
            if (busy) return;
            const fpdUrl = fpdInput?.value.trim() || '';
            if (fpdUrl && !/^https:\/\/fpd-shopify-v2\.s3\.eu-central-1\.amazonaws\.com\/[A-Za-z0-9_-]{12,128}$/.test(fpdUrl)) {
                message.textContent = 'Paste the original FPD backside image link from view 2.';return;
            }
            busy = true; button.disabled = true; button.textContent = 'Comparing…';
            if(fpdButton)fpdButton.disabled=true;
            if(fpdInput)fpdInput.disabled=true;
            const abort=new AbortController();const timeout=setTimeout(()=>abort.abort(),35000);
            try {
                const url = section.dataset.suggestUrl + (fpdUrl ? (section.dataset.suggestUrl.includes('?') ? '&' : '?') + 'fpd_backside=' + encodeURIComponent(fpdUrl) : '');
                const response = await fetch(url, {headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store',signal:abort.signal});
                if (!response.ok || response.redirected) throw Error('Comparison unavailable');
                const result = await response.json();
                message.textContent = result.suggested ? `Suggested: ${result.suggested} · Check and approve this color with the artwork.` : result.message || 'Choose a sheet color, then Approve.';
                const fpdPreview=section.querySelector('[data-fpd-backside-preview]');
                if(fpdPreview){fpdPreview.hidden=!fpdUrl;if(fpdUrl)fpdPreview.src=fpdUrl;else fpdPreview.removeAttribute('src');}
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
                    bindTile(card);
                    list.append(card);
                });
                if (select && !changed && !select.value && result.suggested) select.value = result.suggested;
                updateSwatch();
                if(changed && select?.value)message.textContent='Selected: '+select.value+' · saved with Approve.';
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

            } catch (_) {
                message.textContent = 'Comparison unavailable · choose a sheet color, then Approve.';
            } finally {clearTimeout(timeout);busy = false;button.disabled = section.dataset.locked === 'true';button.textContent = 'Retry comparison';if(fpdInput)fpdInput.disabled=!select || select.disabled;if(fpdButton)fpdButton.disabled=!select || select.disabled;}
        };
        const enqueue=()=>{if(busy||queued)return;queued=true;queue.push(async()=>{queued=false;await compare();});drain();};
        button.addEventListener('click',enqueue);
        fpdButton?.addEventListener('click',()=>{if(!fpdInput.value.trim()){message.textContent='Paste the FPD view 2 backside image link first.';return;}enqueue();});
        fpdInput?.addEventListener('input',()=>{if(!changed && select)select.value='';updateSwatch();const preview=section.querySelector('[data-fpd-backside-preview]');if(preview)preview.hidden=true;message.textContent='Backside source changed · compare again before choosing a color.';});
        select?.addEventListener('change',()=>{changed=true;updateSwatch();message.textContent='Selected: '+select.value+' · saved with Approve.';});
        enqueue();
    });
})();
