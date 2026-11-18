document.addEventListener('change', (event) => {
    if (!(event.target instanceof HTMLSelectElement)
        || !event.target.matches('[data-order-product-status-filter]')) {
        return;
    }

    const status = event.target.value;
    const statusAttribute = {
        Selesai: 'statusSelesai',
        Produksi: 'statusProduksi',
        Service: 'statusService',
    }[status];
    const rows = [...document.querySelectorAll('[data-order-product-row]')];
    let hasVisibleRows = false;

    rows.forEach((row) => {
        const isVisible = !statusAttribute || row.dataset[statusAttribute] === '1';
        row.hidden = !isVisible;
        hasVisibleRows = hasVisibleRows || isVisible;
    });

    const emptyRow = document.querySelector('[data-order-product-filter-empty]');
    if (emptyRow instanceof HTMLTableRowElement) {
        emptyRow.hidden = hasVisibleRows;
    }
});

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const fillAllButton = event.target.closest('[data-service-fill-all]');
    const quantity = fillAllButton?.closest('.stock-quality-quantity-control')?.querySelector('input');

    if (quantity instanceof HTMLInputElement) {
        quantity.value = quantity.max;
    }
});
