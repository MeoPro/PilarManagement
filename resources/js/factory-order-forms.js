const findOrderItemRow = (selector, itemId) => [...document.querySelectorAll(selector)]
    .find((row) => row.dataset.orderItemId === String(itemId));

const updateFactoryImagePreview = (preview, payload, altText) => {
    let link = preview.querySelector('[data-factory-image-link]');
    let image = preview.querySelector('[data-factory-image]');
    let emptyLabel = preview.querySelector('[data-factory-empty-image]');

    if (!link) {
        link = document.createElement('a');
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.dataset.factoryImageLink = '';
        image = document.createElement('img');
        image.className = 'product-catalog-photo product-catalog-photo-large';
        image.dataset.factoryImage = '';
        link.append(image);
        preview.prepend(link);
    }

    if (!emptyLabel && !preview.matches('[data-factory-mockup]')) {
        emptyLabel = document.createElement('span');
        emptyLabel.className = 'muted';
        emptyLabel.dataset.factoryEmptyImage = '';
        preview.append(emptyLabel);
    }

    const imageUrl = payload.factory_drawing_url;
    if (imageUrl) {
        link.href = imageUrl;
        link.hidden = false;
        image.src = imageUrl;
        image.alt = altText;
    } else {
        link.hidden = true;
        image.removeAttribute('src');
        image.alt = '';
    }

    const factoryMockup = preview.closest('[data-factory-mockup]');
    if (factoryMockup) {
        factoryMockup.hidden = !imageUrl;
        const deleteForm = factoryMockup.querySelector('[data-factory-image-delete]');
        if (deleteForm) {
            deleteForm.hidden = !imageUrl;
        }
    }

    if (emptyLabel) {
        emptyLabel.textContent = payload.factory_drawing_empty_label;
        emptyLabel.hidden = Boolean(imageUrl);
    }
};

const updateFactoryOrderView = (itemId, payload) => {
    const productRow = findOrderItemRow('[data-order-product-row]', itemId);
    if (productRow) {
        const mockup = productRow.querySelector('[data-factory-mockup]');
        if (mockup) {
            updateFactoryImagePreview(mockup, payload, `Gambar kerja PF ${productRow.dataset.orderProductName}`);
        }

        const emptyMockup = productRow.querySelector('[data-no-mockup]');
        const gallery = productRow.querySelector('[data-order-product-gallery]');
        if (emptyMockup && gallery) {
            emptyMockup.hidden = Boolean(gallery.querySelector('.order-mockup-item:not([hidden])'));
        }

        const status = productRow.querySelector('[data-factory-summary-status]');
        if (status) {
            status.textContent = payload.factory_status_label;
        }

        const unitPrice = productRow.querySelector('[data-factory-unit-price]');
        const totalPrice = productRow.querySelector('[data-factory-total-price]');
        if (unitPrice) {
            unitPrice.textContent = payload.factory_sale_price_label;
        }
        if (totalPrice) {
            totalPrice.textContent = payload.factory_total_price_label;
        }
    }

    const workRow = findOrderItemRow('[data-factory-work-row]', itemId);
    if (workRow) {
        updateFactoryImagePreview(
            workRow.querySelector('[data-factory-image-preview]'),
            payload,
            `Gambar kerja ${workRow.dataset.orderProductName}`,
        );
        const price = workRow.querySelector('[data-factory-sale-price]');
        if (price) {
            price.textContent = payload.factory_sale_price_label;
        }
    }
};

document.addEventListener('submit', async (event) => {
    if (!(event.target instanceof HTMLFormElement)) {
        return;
    }

    const form = event.target;
    const isDeleteForm = form.matches('[data-factory-image-delete]');
    if (!isDeleteForm && !form.matches('[data-factory-data-form]')) {
        return;
    }

    event.preventDefault();
    if (isDeleteForm && !window.confirm(form.dataset.confirmMessage ?? 'Hapus gambar PF?')) {
        return;
    }

    const submitButton = form.querySelector('button[type="submit"]');
    const feedback = form.querySelector('[data-factory-feedback]');
    const originalButtonLabel = submitButton?.textContent ?? '';

    if (feedback) {
        feedback.hidden = true;
        feedback.classList.remove('error');
    }
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = isDeleteForm ? 'Menghapus...' : 'Menyimpan...';
    }

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        let payload;

        try {
            payload = await response.json();
        } catch {
            throw new Error(`Perubahan PF gagal disimpan (HTTP ${response.status}).`);
        }

        if (!response.ok) {
            const validationMessage = Object.values(payload.errors ?? {}).flat()[0];
            throw new Error(validationMessage ?? payload.message ?? `Perubahan PF gagal disimpan (HTTP ${response.status}).`);
        }

        updateFactoryOrderView(form.dataset.factoryItemId ?? form.closest('[data-order-item-id]')?.dataset.orderItemId, payload);
        const fileInput = form.querySelector('input[type="file"]');
        if (fileInput) {
            fileInput.value = '';
        }

        if (feedback) {
            feedback.textContent = payload.message;
            feedback.hidden = false;
        }
    } catch (error) {
        console.error('Factory order update failed.', error);
        if (feedback) {
            feedback.textContent = error instanceof Error ? error.message : 'Perubahan PF gagal disimpan. Coba lagi.';
            feedback.classList.add('error');
            feedback.hidden = false;
            feedback.setAttribute('role', 'alert');
        }
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalButtonLabel;
        }
    }
});
