document.addEventListener('click', (event) => {
    const target = event.target instanceof Element
        ? event.target.closest('[data-unit-focus-toggle]')
        : null;

    if (!(target instanceof HTMLButtonElement)) {
        return;
    }

    const form = target.form;
    if (!form) {
        console.error('Unit focus toggle is not inside a form.');
        return;
    }

    event.preventDefault();

    if (target.dataset.pending === 'true') {
        return;
    }

    const thumb = target.querySelector('.unit-focus-toggle-thumb');
    const isFactory = target.getAttribute('aria-checked') !== 'true';
    target.dataset.pending = 'true';
    target.setAttribute('aria-checked', String(isFactory));
    target.title = `Beralih ke ${isFactory ? 'PB' : 'PF'}`;

    let submitTimeout = null;
    const submitForm = () => {
        if (target.dataset.pending !== 'true') {
            return;
        }

        delete target.dataset.pending;
        if (submitTimeout !== null) {
            window.clearTimeout(submitTimeout);
        }
        thumb?.removeEventListener('transitionend', handleTransitionEnd);
        form.requestSubmit(target);
    };
    const handleTransitionEnd = (transitionEvent) => {
        if (transitionEvent.propertyName === 'transform') {
            submitForm();
        }
    };

    if (!thumb || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        submitForm();
        return;
    }

    thumb.addEventListener('transitionend', handleTransitionEnd);
    submitTimeout = window.setTimeout(submitForm, 300);
});
