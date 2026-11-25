document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const trigger = event.target.closest('[data-stock-quality-result]');
    const dialog = document.querySelector('[data-stock-quality-dialog]');
    const form = dialog?.querySelector('[data-stock-quality-form]');

    if (trigger && dialog instanceof HTMLDialogElement && form instanceof HTMLFormElement) {
        const quantity = form.querySelector('[data-stock-quality-quantity]');
        const result = form.querySelector('[data-stock-quality-result-value]');
        const severityField = form.querySelector('[data-stock-quality-severity-field]');
        const severity = form.querySelector('[data-stock-quality-severity]');
        const evidence = form.querySelector('[data-stock-quality-evidence]');
        const maxQuantity = Number.parseInt(trigger.dataset.stockQualityMax ?? '', 10);
        const qualityResult = trigger.dataset.stockQualityResult;
        const actionUrl = trigger.dataset.stockQualityUrl;

        if (!(quantity instanceof HTMLInputElement)
            || !(result instanceof HTMLInputElement)
            || !(severityField instanceof HTMLElement)
            || !(severity instanceof HTMLSelectElement)
            || !(evidence instanceof HTMLInputElement)
            || !qualityResult
            || !actionUrl
            || !Number.isInteger(maxQuantity)
            || maxQuantity < 1) {
            return;
        }

        form.action = actionUrl;
        result.value = qualityResult;
        severityField.hidden = qualityResult !== 'Rusak';
        severity.required = qualityResult === 'Rusak';
        severity.value = '';
        evidence.required = ['Rusak', 'Kurang'].includes(qualityResult);
        quantity.max = String(maxQuantity);
        quantity.value = '';
        form.querySelector('[data-stock-quality-title]').textContent = `Pemeriksaan ${qualityResult}`;
        form.querySelector('[data-stock-quality-description]').textContent =
            `${trigger.dataset.stockQualityProduct} · maksimal ${maxQuantity} unit`;
        form.querySelector('[data-stock-quality-submit]').textContent = `Simpan ${qualityResult}`;

        if (!dialog.open) {
            dialog.showModal();
        }

        quantity.focus();
        return;
    }

    const fillAllButton = event.target.closest('[data-stock-quality-fill-all]');
    if (fillAllButton) {
        const activeDialog = fillAllButton.closest('dialog');
        const quantity = activeDialog?.querySelector('[data-stock-quality-quantity]');
        if (quantity instanceof HTMLInputElement) {
            quantity.value = quantity.max;
        }

        return;
    }

    const closeButton = event.target.closest('[data-stock-quality-close]');
    if (closeButton) {
        const activeDialog = closeButton.closest('dialog');
        if (activeDialog instanceof HTMLDialogElement) {
            activeDialog.close();
        }
    }

    const serviceResultButton = event.target.closest('button[name="result"]');
    const serviceForm = serviceResultButton?.closest('[data-quality-evidence-form]');
    const serviceEvidence = serviceForm?.querySelector('[data-quality-evidence]');
    if (serviceResultButton instanceof HTMLButtonElement && serviceEvidence instanceof HTMLInputElement) {
        serviceEvidence.required = ['Rusak', 'Kurang'].includes(serviceResultButton.value);
    }
});

document.addEventListener('change', (event) => {
    if (!(event.target instanceof HTMLSelectElement)
        || !event.target.matches('[data-quality-evidence-result]')) {
        return;
    }

    const evidence = event.target.closest('form')?.querySelector('[data-quality-evidence]');
    if (evidence instanceof HTMLInputElement) {
        evidence.required = ['Rusak', 'Kurang'].includes(event.target.value);
    }
});

document.addEventListener('click', (event) => {
    if (event.target instanceof HTMLDialogElement
        && event.target.matches('[data-stock-quality-dialog]')) {
        event.target.close();
    }
});

document.addEventListener('close', (event) => {
    if (!(event.target instanceof HTMLDialogElement)
        || !event.target.matches('[data-stock-quality-dialog]')) {
        return;
    }

    const form = event.target.querySelector('[data-stock-quality-form]');
    if (form instanceof HTMLFormElement) {
        form.reset();
        const severityField = form.querySelector('[data-stock-quality-severity-field]');
        const severity = form.querySelector('[data-stock-quality-severity]');
        const evidence = form.querySelector('[data-stock-quality-evidence]');
        if (severityField instanceof HTMLElement) {
            severityField.hidden = true;
        }
        if (severity instanceof HTMLSelectElement) {
            severity.required = false;
        }
        if (evidence instanceof HTMLInputElement) {
            evidence.required = false;
        }
    }
}, true);
