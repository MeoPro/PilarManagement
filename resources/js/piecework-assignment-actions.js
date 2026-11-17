document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const trigger = event.target.closest('[data-piecework-action]');
    const dialog = document.querySelector('[data-piecework-action-dialog]');
    const form = dialog?.querySelector('[data-piecework-action-form]');

    if (trigger && dialog instanceof HTMLDialogElement && form instanceof HTMLFormElement) {
        const action = trigger.dataset.pieceworkAction;
        const context = trigger.dataset.pieceworkContext ?? 'borongan';
        const isCancellation = action === 'cancel';
        const remaining = Math.max(Number.parseInt(trigger.dataset.pieceworkRemaining ?? '0', 10) || 0, 0);
        const quantity = form.querySelector('[data-piecework-action-quantity]');
        const methodOverride = form.querySelector('[data-piecework-method]');

        if (!(quantity instanceof HTMLInputElement) || !(methodOverride instanceof HTMLInputElement)) {
            return;
        }

        form.action = trigger.dataset.pieceworkUrl;
        methodOverride.disabled = isCancellation;
        methodOverride.value = isCancellation ? '' : 'PATCH';
        quantity.min = remaining === 0 ? '0' : '1';
        quantity.max = String(remaining);
        quantity.value = remaining === 0 ? '0' : '';
        form.querySelector('[data-piecework-action-title]').textContent = isCancellation
            ? `Batalkan tugas ${context}`
            : `Selesaikan tugas ${context}`;
        form.querySelector('[data-piecework-action-product]').textContent = trigger.dataset.pieceworkProduct;
        form.querySelector('[data-piecework-quantity-label]').textContent = isCancellation
            ? 'Jumlah yang dibatalkan'
            : 'Jumlah yang diselesaikan';
        form.querySelector('[data-piecework-action-submit]').textContent = isCancellation
            ? 'Konfirmasi pembatalan'
            : 'Konfirmasi penyelesaian';

        if (!dialog.open) {
            dialog.showModal();
        }

        quantity.focus();
        return;
    }

    const fillAllButton = event.target.closest('[data-piecework-fill-all]');
    if (fillAllButton) {
        const activeDialog = fillAllButton.closest('dialog');
        const quantity = activeDialog?.querySelector('[data-piecework-action-quantity]');
        if (quantity instanceof HTMLInputElement) {
            quantity.value = quantity.max;
        }

        return;
    }

    const closeButton = event.target.closest('[data-piecework-action-close]');
    if (closeButton) {
        const activeDialog = closeButton.closest('dialog');
        if (activeDialog instanceof HTMLDialogElement) {
            activeDialog.close();
        }
    }
});

document.addEventListener('click', (event) => {
    if (event.target instanceof HTMLDialogElement
        && event.target.matches('[data-piecework-action-dialog]')) {
        event.target.close();
    }
});

document.addEventListener('close', (event) => {
    if (!(event.target instanceof HTMLDialogElement)
        || !event.target.matches('[data-piecework-action-dialog]')) {
        return;
    }

    const form = event.target.querySelector('[data-piecework-action-form]');
    const methodOverride = form?.querySelector('[data-piecework-method]');
    if (form instanceof HTMLFormElement && methodOverride instanceof HTMLInputElement) {
        form.reset();
        methodOverride.disabled = true;
    }
}, true);
