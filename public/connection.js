(() => {
    'use strict';

    const form = document.querySelector('[data-connection-form]');
    const submit = form?.querySelector('[data-submit]');
    const expiry = document.querySelector('[data-expiry]');
    const expiryNotice = document.querySelector('[data-expiry-notice]');
    const selectionStatus = document.querySelector('[data-selection-status]');
    const formStatus = document.querySelector('[data-form-status]');
    const reveal = document.querySelector('[data-reveal]');
    const password = document.querySelector('#password');
    const originalLabel = submit?.innerHTML;
    const startedAt = performance.now();
    const initialSeconds = expiry ? Number(expiry.dataset.expiry) - Number(expiry.dataset.serverTime) : Infinity;
    let submitting = false;

    const secondsRemaining = () => initialSeconds - (performance.now() - startedAt) / 1000;

    function update() {
        const seconds = secondsRemaining();
        const expired = seconds <= 0;
        if (expiry) expiry.textContent = expired ? 'This link has expired.' : `This link expires in ${Math.ceil(seconds / 60)} min.`;
        if (expiryNotice) expiryNotice.hidden = !expired;
        const selected = form?.querySelectorAll('input[name="shops[]"]:checked').length ?? 0;
        const needsSelection = form?.hasAttribute('data-approval') && selected === 0;
        if (selectionStatus) selectionStatus.textContent = selected ? `${selected} ${selected === 1 ? 'business' : 'businesses'} selected. You can switch in chat.` : 'Select at least one business to continue.';
        if (submit) submit.disabled = submitting || expired || needsSelection;
    }

    if (reveal && password) {
        reveal.hidden = false;
        reveal.addEventListener('click', () => {
            const show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            reveal.textContent = show ? 'Hide' : 'Show';
            reveal.setAttribute('aria-pressed', String(show));
        });
    }

    form?.addEventListener('change', update);
    form?.addEventListener('submit', event => {
        if (submitting || secondsRemaining() <= 0) {
            event.preventDefault();
            update();
            return;
        }
        if (form.hasAttribute('data-approval') && !form.querySelector('input[name="shops[]"]:checked')) {
            event.preventDefault();
            update();
            return;
        }
        submitting = true;
        form.setAttribute('aria-busy', 'true');
        if (submit) {
            submit.textContent = submit.dataset.busyLabel;
            submit.setAttribute('aria-busy', 'true');
        }
        if (formStatus) formStatus.textContent = 'This may take a moment. Please keep this page open.';
        update();
    });

    window.addEventListener('pageshow', () => {
        submitting = false;
        form?.removeAttribute('aria-busy');
        if (submit) {
            submit.innerHTML = originalLabel;
            submit.removeAttribute('aria-busy');
        }
        if (password) password.type = 'password';
        if (reveal) {
            reveal.textContent = 'Show';
            reveal.setAttribute('aria-pressed', 'false');
        }
        update();
    });
    document.querySelector('#connection-error')?.focus();
    update();
    if (expiry) window.setInterval(update, 10000);
})();
