document.querySelectorAll('[data-filter]').forEach((button) => {
    button.addEventListener('click', () => {
        document.querySelectorAll('[data-filter]').forEach((item) => item.classList.remove('active'));
        button.classList.add('active');

        const filter = button.dataset.filter;

        document.querySelectorAll('.job-card').forEach((card) => {
            let visible = true;

            if (filter === 'single') visible = card.dataset.type === 'single' && card.dataset.custom !== 'yes';
            if (filter === 'double') visible = card.dataset.type === 'double' && card.dataset.custom !== 'yes';
            if (filter === 'custom') visible = card.dataset.custom === 'yes';
            if (filter === 'ready') visible = card.dataset.ready === 'yes';
            if (filter === 'unverified') visible = !card.dataset.decision;
            if (filter === 'wrong') visible = card.dataset.decision === 'WRONG' || card.dataset.status === 'REVIEW' || card.dataset.status === 'MISSING';

            card.style.display = visible ? '' : 'none';
        });
    });
});

document.querySelectorAll('[data-select-item]').forEach((box) => {
    box.addEventListener('change', () => {
        const input = document.querySelector(`[data-qty-item="${box.dataset.selectItem}"]`);
        const card = box.closest('.job-card');
        const feedback = card?.querySelector('[data-queue-feedback]');

        if (!input) return;

        input.value = box.checked ? input.max : 0;

        if (feedback) {
            feedback.textContent = box.checked ? `Selected ${input.value} set(s).` : 'Selection cleared.';
        }
    });
});

document.querySelectorAll('[data-qty-item]').forEach((input) => {
    input.addEventListener('input', () => {
        const box = document.querySelector(`[data-select-item="${input.dataset.qtyItem}"]`);
        const card = input.closest('.job-card');
        const feedback = card?.querySelector('[data-queue-feedback]');
        const value = parseInt(input.value || '0', 10);

        if (box) box.checked = value > 0;
        if (feedback) feedback.textContent = value > 0 ? `Selected ${value} set(s).` : 'Selection cleared.';
    });
});

document.querySelectorAll('[data-inline-action]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
        if (!window.fetch) return;

        event.preventDefault();

        const card = form.closest('[data-inline-card]');
        const feedback = card?.querySelector('[data-inline-feedback]');
        const decision = card?.querySelector('[data-inline-decision]');
        const buttons = card ? Array.from(card.querySelectorAll('button')) : [];
        const submitter = event.submitter || form.querySelector('button');

        if (!card || form.dataset.busy === '1') return;

        form.dataset.busy = '1';
        buttons.forEach((button) => button.disabled = true);

        if (feedback) {
            feedback.textContent = 'Saving...';
            feedback.classList.remove('bad', 'ok');
        }

        try {
            const response = await fetch(form.action, {
                method: form.method || 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new FormData(form),
                credentials: 'same-origin',
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'Action failed. Please try again.');
            }

            card.classList.remove('approved-card', 'wrong-card');

            if (payload.item.card_classes) {
                payload.item.card_classes.split(' ').filter(Boolean).forEach((name) => card.classList.add(name));
            }

            card.dataset.ready = payload.item.ready_for_print ? 'yes' : 'no';
            card.dataset.decision = payload.item.decision || '';

            if (decision) decision.innerHTML = payload.item.decision_html;

            if (feedback) {
                feedback.textContent = payload.message;
                feedback.classList.add('ok');
                feedback.focus({preventScroll: true});
            } else if (submitter) {
                submitter.focus({preventScroll: true});
            }
        } catch (error) {
            if (feedback) {
                feedback.textContent = error.message;
                feedback.classList.add('bad');
                feedback.focus({preventScroll: true});
            }
        } finally {
            form.dataset.busy = '0';
            buttons.forEach((button) => button.disabled = false);
        }
    });
});

document.querySelectorAll('#queue-batch-form').forEach((form) => {
    form.addEventListener('submit', () => {
        form.querySelectorAll('button').forEach((button) => button.disabled = true);
    });
});
