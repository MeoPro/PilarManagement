document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const openButton = event.target.closest('[data-open-payroll-dialog]');
    if (openButton) {
        const dialog = document.getElementById(openButton.dataset.openPayrollDialog);
        if (dialog instanceof HTMLDialogElement && !dialog.open) {
            dialog.showModal();
        }

        return;
    }

    const closeButton = event.target.closest('[data-close-payroll-dialog]');
    if (closeButton) {
        const dialog = closeButton.closest('dialog');
        if (dialog instanceof HTMLDialogElement) {
            dialog.close();
        }

        return;
    }

    if (event.target instanceof HTMLDialogElement && event.target.classList.contains('payroll-payment-dialog')) {
        event.target.close();
    }
});
