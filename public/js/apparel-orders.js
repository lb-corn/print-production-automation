(() => {

        const modal = document.getElementById('order-modal');
        const modalShell = modal.querySelector('.order-modal');
        const modalBody = document.getElementById('modal-body');
        const modalOrderNumber = document.getElementById('modal-order-number');
        const modalStatusBadge = document.getElementById('modal-status-badge');
        const closeModal = document.getElementById('close-modal');
        const filterForm = document.querySelector('[data-filter-form]');
        const syncForm = document.querySelector('[data-sync-form]');
        const tablePanel = document.querySelector('[data-table-panel]');
        const canHoverPreview = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        let activeViewButton = null;
        let hoverPreviewTimer = null;

        document.querySelectorAll('[data-disable-on-submit]').forEach((form) => {
            form.addEventListener('submit', () => {
                const submitButtons = [
                    ...form.querySelectorAll('button[type="submit"]'),
                    ...(form.id ? document.querySelectorAll(`button[type="submit"][form="${form.id}"]`) : []),
                ];

                submitButtons.forEach((button) => {
                    button.disabled = true;
                });
            });
        });

        if (filterForm) {
            filterForm.addEventListener('submit', () => {
                setFormLoading(filterForm, true);
                tablePanel?.setAttribute('aria-busy', 'true');
                tablePanel?.classList.add('is-loading');
            });
        }

        if (syncForm) {
            syncForm.addEventListener('submit', () => {
                setFormLoading(syncForm, true);
            });
        }

        window.addEventListener('pageshow', () => {
            if (filterForm) {
                setFormLoading(filterForm, false);
            }
            if (syncForm) {
                setFormLoading(syncForm, false);
            }
            tablePanel?.removeAttribute('aria-busy');
            tablePanel?.classList.remove('is-loading');
        });

        document.querySelectorAll('.view-order').forEach((button) => {
            button.addEventListener('click', async (event) => {
                event.preventDefault();
                clearHoverPreviewTimer();
                await openOrderFromButton(button);
            });

            if (canHoverPreview) {
                button.addEventListener('mouseenter', () => {
                    clearHoverPreviewTimer();
                    hoverPreviewTimer = window.setTimeout(() => openOrderFromButton(button), 500);
                });
                button.addEventListener('mouseleave', clearHoverPreviewTimer);
            }
        });

        closeModal.addEventListener('click', closeOrderModal);
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeOrderModal();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                clearHoverPreviewTimer();
                if (!modal.hidden) {
                    closeOrderModal();
                }
            }
        });

        function setFormLoading(form, loading) {
            const submitButton = form.querySelector('button[type="submit"]');
            const label = submitButton?.querySelector('.button-label');
            const resetLink = form.querySelector('[data-reset-link]');

            form.setAttribute('aria-busy', loading ? 'true' : 'false');

            if (submitButton) {
                submitButton.disabled = loading;
                submitButton.classList.toggle('button-loading', loading);
            }

            if (label && submitButton) {
                label.textContent = loading
                    ? submitButton.dataset.loadingLabel
                    : submitButton.dataset.defaultLabel;
            }

            if (resetLink) {
                resetLink.classList.toggle('disabled', loading);
                resetLink.setAttribute('aria-disabled', loading ? 'true' : 'false');
            }
        }

        async function openOrderFromButton(button) {
            if (!modal.hidden || button.disabled) {
                return;
            }

            clearHoverPreviewTimer();
            button.disabled = true;
            activeViewButton = button;

            try {
                const response = await fetch(button.dataset.url, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error('Unable to load order details.');
                }

                renderModal(await response.json());
                openModal();
            } catch (error) {
                alert(error.message);
            } finally {
                button.disabled = false;
            }
        }

        function clearHoverPreviewTimer() {
            if (hoverPreviewTimer) {
                window.clearTimeout(hoverPreviewTimer);
                hoverPreviewTimer = null;
            }
        }

        function openModal() {
            modal.hidden = false;
            document.body.classList.add('apparel-modal-open');
            modalShell.focus();
        }

        function closeOrderModal() {
            modal.hidden = true;
            document.body.classList.remove('apparel-modal-open');
            if (activeViewButton) {
                activeViewButton.focus();
            }
        }

        function renderModal(payload) {
            const order = payload.order;
            modalBody.innerHTML = '';
            modalOrderNumber.textContent = order.shopify_order_number ? order.shopify_order_number : '';
            modalStatusBadge.innerHTML = '';
            modalStatusBadge.appendChild(statusBadge(order.shopify_fulfillment_status));

            const customFields = Array.isArray(order.custom_fields) ? order.custom_fields : [];
            const visibleCustomFields = customFields.filter((item) => !String(item.key ?? '').startsWith('_'));
            const technicalCustomFields = customFields.filter((item) => String(item.key ?? '').startsWith('_'));

            const layout = document.createElement('div');
            layout.className = 'order-modal-grid';
            const info = document.createElement('div');
            info.className = 'order-modal-main';
            const side = document.createElement('aside');
            side.className = 'order-modal-aside';

            info.appendChild(modalSection('Order', [
                ['Order Number', order.shopify_order_number],
                ['Ordered Date', formatDate(order.ordered_at)],
                ['Shopify Fulfillment Status', order.shopify_fulfillment_status],
                ['Shopify Financial Status', order.shopify_financial_status],
                ['Cancellation Date', formatDate(order.shopify_cancelled_at)],
                ['Closed Date', formatDate(order.shopify_closed_at)],
            ]));

            info.appendChild(modalSection('Apparel Details', [
                ['Product Title', order.product_title],
                ['Variant Title', order.variant_title],
                ['Style', order.style],
                ['Color', order.color],
                ['Size', order.size],
                ['Display Name', order.display_name],
                ['Quantity', order.quantity],
            ]));

            info.appendChild(listSection('Personalization', visibleCustomFields, 'key'));

            info.appendChild(modalSection('Local Tracking', [
                ['Tracking Number', order.tracking_number || 'Not added'],
                ['Last Updated Date', formatDate(order.tracking_updated_at) || 'Not added'],
                ['Updated By', order.tracking_updated_by?.name || 'Not added'],
            ]));

            info.appendChild(modalSection('Remark', [
                ['Remark', order.remark || 'Not added'],
                ['Remark Updated At', formatDate(order.remark_updated_at) || 'Not added'],
                ['Remark Updated By', order.remark_updated_by?.name || 'Not added'],
                ['Remark Processed', order.remark_processed_at ? 'Yes' : 'No'],
                ['Remark Processed At', formatDate(order.remark_processed_at) || 'Not added'],
                ['Remark Processed By', order.remark_processed_by?.name || 'Not added'],
            ]));

            info.appendChild(modalSection('Shopify References', [
                ['Order GID', order.shopify_order_gid, true],
                ['Line Item GID', order.shopify_line_item_gid, true],
                ['Product GID', order.shopify_product_gid, true],
                ['Variant GID', order.shopify_variant_gid, true],
                ['Product Handle', order.product_handle],
                ['Last Synchronized Date', formatDate(order.last_synced_at)],
            ]));

            info.appendChild(listSection('Selected Options', order.selected_options, 'name'));
            info.appendChild(tagSection('Product Tags', order.product_tags));

            if (technicalCustomFields.length > 0) {
                info.appendChild(technicalSection(technicalCustomFields));
            }

            side.appendChild(imagePanel(order, payload.storefront_url));

            layout.append(info, side);
            modalBody.appendChild(layout);
        }

        function modalSection(title, fields) {
            const section = document.createElement('section');
            section.className = 'order-modal-section';
            const heading = document.createElement('h3');
            heading.textContent = title;
            section.appendChild(heading);
            section.appendChild(detailGrid(fields));
            return section;
        }

        function detailGrid(fields) {
            const dl = document.createElement('dl');
            dl.className = 'order-modal-definition-grid';

            fields.forEach(([label, value, copyable]) => {
                const dt = document.createElement('dt');
                const dd = document.createElement('dd');
                dt.textContent = label;
                if (copyable) {
                    dd.appendChild(idValue(value));
                } else {
                    dd.textContent = value ?? '-';
                }
                dl.append(dt, dd);
            });

            return dl;
        }

        function listSection(title, items, keyName) {
            const section = document.createElement('section');
            section.className = 'order-modal-section';
            const heading = document.createElement('h3');
            heading.textContent = title;
            section.appendChild(heading);

            if (!Array.isArray(items) || items.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'muted';
                empty.textContent = '-';
                section.appendChild(empty);
                return section;
            }

            section.appendChild(detailGrid(items.map((item) => [item[keyName], item.value])));
            return section;
        }

        function technicalSection(items) {
            const section = document.createElement('section');
            section.className = 'order-modal-section';
            const details = document.createElement('details');
            const summary = document.createElement('summary');
            summary.textContent = 'Show technical fields';
            details.appendChild(summary);
            details.appendChild(detailGrid(items.map((item) => [item.key, item.value])));
            section.appendChild(details);
            return section;
        }

        function tagSection(title, tags) {
            const section = document.createElement('section');
            section.className = 'order-modal-section';
            const heading = document.createElement('h3');
            heading.textContent = title;
            section.appendChild(heading);
            const value = document.createElement('p');
            value.textContent = Array.isArray(tags) && tags.length ? tags.join(', ') : '-';
            section.appendChild(value);
            return section;
        }

        function imagePanel(order, storefrontUrl) {
            const fragment = document.createDocumentFragment();

            if (order.image_url) {
                const link = document.createElement('a');
                link.className = 'order-modal-image-link';
                link.href = order.image_url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.title = 'Open full image';
                link.setAttribute('aria-label', `Open full image for ${order.product_title ?? 'jersey'}`);
                const image = document.createElement('img');
                image.className = 'order-modal-jersey-image';
                image.src = order.image_url;
                image.alt = '';
                link.appendChild(image);
                fragment.appendChild(link);
            } else {
                const empty = document.createElement('div');
                empty.className = 'order-modal-image-empty';
                empty.textContent = 'No image';
                fragment.appendChild(empty);
            }

            const title = document.createElement('h3');
            title.textContent = order.product_title ?? '-';
            fragment.appendChild(title);

            const variant = document.createElement('p');
            variant.className = 'modal-panel-muted';
            variant.textContent = order.variant_title ?? 'No variant';
            fragment.appendChild(variant);

            if (storefrontUrl) {
                const link = document.createElement('a');
                link.className = 'button button-small button-outline';
                link.href = storefrontUrl;
                link.target = '_blank';
                link.rel = 'noreferrer';
                link.textContent = 'Storefront';
                fragment.appendChild(link);
            }

            const badges = document.createElement('div');
            badges.className = 'badge-stack compact modal-panel-badges';
            badges.appendChild(statusBadge(order.shopify_fulfillment_status));
            if (order.shopify_financial_status) {
                const financial = document.createElement('span');
                financial.className = 'badge badge-neutral';
                financial.textContent = String(order.shopify_financial_status).replaceAll('_', ' ');
                badges.appendChild(financial);
            }
            fragment.appendChild(badges);

            return fragment;
        }

        function statusBadge(status) {
            const badge = document.createElement('span');
            badge.className = `badge ${badgeClass(status)}`;
            badge.textContent = status ? String(status).replaceAll('_', ' ') : '-';
            return badge;
        }

        function badgeClass(status) {
            switch (status) {
                case 'UNFULFILLED':
                    return 'badge-yellow';
                case 'PARTIALLY_FULFILLED':
                    return 'badge-orange';
                case 'FULFILLED':
                    return 'badge-green';
                case 'IN_PROGRESS':
                    return 'badge-blue';
                case 'ON_HOLD':
                case 'RESTOCKED':
                    return 'badge-gray';
                case 'SCHEDULED':
                    return 'badge-purple';
                default:
                    return 'badge-neutral';
            }
        }

        function idValue(value) {
            const wrapper = document.createElement('span');
            wrapper.className = 'copy-row';
            const code = document.createElement('code');
            code.textContent = value ?? '-';
            wrapper.appendChild(code);

            if (value && navigator.clipboard) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'copy-button';
                button.textContent = 'Copy';
                button.addEventListener('click', async () => {
                    await navigator.clipboard.writeText(value);
                    button.textContent = 'Copied';
                    window.setTimeout(() => button.textContent = 'Copy', 1200);
                });
                wrapper.appendChild(button);
            }

            return wrapper;
        }

        function formatDate(value) {
            if (!value) {
                return null;
            }

            return new Date(value).toLocaleString();
        }

})();
