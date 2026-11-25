const formatNumber = new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 2,
});

const parseMoney = (value) => Number(value.replace(/,/g, '')) || 0;

const formatMoneyInput = (input) => {
    const parts = input.value.replace(/[^\d.]/g, '').split('.');
    const whole = parts.shift() ?? '';
    const fraction = parts.join('').slice(0, 2);

    input.value = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
        + (input.value.includes('.') ? `.${fraction}` : '');
};

const utcDateValue = (value) => {
    const [year, month, day] = value.split('-').map(Number);
    if (!year || !month || !day) {
        return Number.NaN;
    }

    return Date.UTC(year, month - 1, day);
};

const isoDateValue = (timestamp) => {
    const date = new Date(timestamp);
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
};

const updateDeadlineControl = (control, source = 'date') => {
    const dateInput = control.querySelector('[data-deadline-date]');
    const daysInput = control.querySelector('[data-deadline-days]');
    const isLocked = control.dataset.deadlineLocked === 'true';
    const today = control.dataset.deadlineToday;
    const minimumDays = Number(control.dataset.deadlineMinimumDays ?? 0);

    dateInput.readOnly = isLocked;
    daysInput.readOnly = isLocked;

    if (source === 'days' && !isLocked) {
        const days = Number(daysInput.value);
        if (daysInput.value === '' || !Number.isInteger(days) || days < minimumDays) {
            dateInput.value = '';
            return;
        }

        dateInput.value = isoDateValue(utcDateValue(today) + days * 24 * 60 * 60 * 1000);
        return;
    }

    const duration = (utcDateValue(dateInput.value) - utcDateValue(today)) / (24 * 60 * 60 * 1000);
    if (!Number.isInteger(duration) || duration < minimumDays) {
        daysInput.value = '';
        return;
    }

    daysInput.value = String(duration);
};

const initializeDeadlineControl = (control) => {
    if (control.dataset.deadlineInitialized === 'true') {
        updateDeadlineControl(control);
        return;
    }

    control.dataset.deadlineInitialized = 'true';
    control.querySelector('[data-deadline-date]').addEventListener('input', () => updateDeadlineControl(control, 'date'));
    control.querySelector('[data-deadline-days]').addEventListener('input', () => updateDeadlineControl(control, 'days'));
    updateDeadlineControl(control);
};

const initializeAssignmentForms = () => {
    document.querySelectorAll('[data-piecework-assignment-form]').forEach((form) => {
        if (form.dataset.pieceworkAssignmentInitialized === 'true') {
            return;
        }

        form.dataset.pieceworkAssignmentInitialized = 'true';
        const quantityInput = form.querySelector('[name="target_quantity"]');
        const priceInput = form.querySelector('[data-piecework-assignment-price]');
        const totalOutput = form.querySelector('[data-piecework-assignment-total]');

        const updateTotal = () => {
            const quantity = Number(quantityInput.value) || 0;
            const price = parseMoney(priceInput.value);
            totalOutput.textContent = `Rp ${formatNumber.format(quantity * price)}`;
        };

        form.addEventListener('input', (event) => {
            if (event.target === priceInput) {
                formatMoneyInput(priceInput);
            }

            updateTotal();
        });
        updateTotal();
    });
};

const initializePieceworkItems = () => {
    document.querySelectorAll('[data-piecework-list]').forEach((list) => {
        if (list.dataset.pieceworkInitialized === 'true') {
            return;
        }

        const form = list.closest('form');
        const totalOutput = form.querySelector('[data-piecework-total]');
        const addButton = form.querySelector('[data-add-piecework-item]');
        list.dataset.pieceworkInitialized = 'true';

        const syncOrderProduct = (row, resetProduct = false) => {
            const orderSelect = row.querySelector('[data-piecework-spk]');
            const productSelect = row.querySelector('[data-piecework-order-product]');
            const productName = row.querySelector('[data-piecework-product-name]');
            const assignmentInput = row.querySelector('[data-piecework-assignment-id]');
            const quantityInput = row.querySelector('[data-piecework-quantity]');
            const priceInput = row.querySelector('[data-piecework-price]');
            const deadlineInput = row.querySelector('[data-deadline-date]');
            const deadlineControl = row.querySelector('[data-deadline-control]');
            const taskSelect = row.querySelector('[data-piecework-task-assignment]');

            if (taskSelect) {
                const selectedTask = taskSelect.selectedOptions[0];
                if (selectedTask?.value) {
                    const remaining = Number(selectedTask.dataset.remaining) || 0;
                    productName.value = selectedTask.dataset.product;
                    quantityInput.max = String(remaining);
                    if (Number(quantityInput.value) > remaining) {
                        quantityInput.value = String(remaining);
                    }
                    deadlineInput.value = selectedTask.dataset.deadline;
                    deadlineControl.dataset.deadlineLocked = 'true';
                    if (selectedTask.dataset.unitPrice) {
                        priceInput.value = selectedTask.dataset.unitPrice;
                        formatMoneyInput(priceInput);
                        priceInput.readOnly = true;
                    }
                } else {
                    quantityInput.removeAttribute('max');
                    deadlineControl.dataset.deadlineLocked = 'false';
                    priceInput.readOnly = false;
                }

                updateDeadlineControl(deadlineControl);
                return;
            }

            if (!orderSelect || !productSelect || !productName) {
                return;
            }

            if (resetProduct) {
                productSelect.value = '';
                productName.value = '';
            }

            productSelect.querySelectorAll('option[data-order-id]').forEach((option) => {
                const isVisible = option.dataset.orderId === orderSelect.value;
                option.hidden = !isVisible;
                option.disabled = !isVisible;
            });

            const selectedProduct = productSelect.selectedOptions[0];
            if (selectedProduct?.dataset.orderId !== orderSelect.value) {
                productSelect.value = '';
                if (assignmentInput) {
                    assignmentInput.value = '';
                }
                quantityInput.removeAttribute('max');
                deadlineControl.dataset.deadlineLocked = 'false';
                priceInput.readOnly = false;
            } else {
                productName.value = selectedProduct.dataset.product;
                if (assignmentInput) {
                    assignmentInput.value = selectedProduct.dataset.assignmentId ?? '';
                }

                if (selectedProduct.dataset.assignmentId) {
                    const remaining = Number(selectedProduct.dataset.remaining) || 0;
                    quantityInput.max = String(remaining);
                    if (Number(quantityInput.value) > remaining) {
                        quantityInput.value = String(remaining);
                    }
                    deadlineInput.value = selectedProduct.dataset.deadline;
                    deadlineControl.dataset.deadlineLocked = 'true';
                    if (selectedProduct.dataset.unitPrice) {
                        priceInput.value = selectedProduct.dataset.unitPrice;
                        formatMoneyInput(priceInput);
                        priceInput.readOnly = true;
                    } else {
                        priceInput.readOnly = false;
                    }
                } else {
                    quantityInput.removeAttribute('max');
                    deadlineControl.dataset.deadlineLocked = 'false';
                    priceInput.readOnly = false;
                }
            }

            updateDeadlineControl(deadlineControl);
        };

        const updateRows = () => {
            const rows = [...list.querySelectorAll('[data-piecework-item]')];
            let total = 0;

            rows.forEach((row, index) => {
                row.querySelectorAll('[name]').forEach((input) => {
                    input.name = input.name.replace(/piecework_items\[\d+\]/, `piecework_items[${index}]`);
                });

                syncOrderProduct(row);
                const quantity = Number(row.querySelector('[data-piecework-quantity]').value) || 0;
                const price = parseMoney(row.querySelector('[data-piecework-price]').value);
                const lineTotal = quantity * price;
                total += lineTotal;
                row.querySelector('[data-piecework-line-total]').textContent = `Rp ${formatNumber.format(lineTotal)}`;
                row.querySelector('[data-remove-piecework-item]').disabled = rows.length === 1;
            });

            totalOutput.textContent = `Rp ${formatNumber.format(total)}`;
        };

        list.addEventListener('input', (event) => {
            if (event.target.matches('[data-piecework-price]')) {
                formatMoneyInput(event.target);
            }

            updateRows();
        });

        list.addEventListener('change', (event) => {
            const row = event.target.closest('[data-piecework-item]');
            if (event.target.matches('[data-piecework-spk]')) {
                syncOrderProduct(row, true);
            } else if (
                event.target.matches('[data-piecework-order-product]')
                || event.target.matches('[data-piecework-task-assignment]')
            ) {
                syncOrderProduct(row);
                updateRows();
            }
        });

        list.addEventListener('click', (event) => {
            const removeButton = event.target.closest('[data-remove-piecework-item]');
            if (!removeButton || list.querySelectorAll('[data-piecework-item]').length === 1) {
                return;
            }

            removeButton.closest('[data-piecework-item]').remove();
            updateRows();
        });

        addButton.addEventListener('click', () => {
            const row = list.querySelector('[data-piecework-item]').cloneNode(true);
            row.querySelector('[name$="[product_name]"]').value = '';
            row.querySelector('[name$="[quantity]"]').value = '1';
            row.querySelector('[name$="[unit_price]"]').value = '';
            row.querySelector('[name$="[target_deadline]"]').value = '';
            const deadlineControl = row.querySelector('[data-deadline-control]');
            deadlineControl.querySelector('[data-deadline-days]').value = '';
            deadlineControl.dataset.deadlineLocked = 'false';
            delete deadlineControl.dataset.deadlineInitialized;
            const orderSelect = row.querySelector('[data-piecework-spk]');
            const productSelect = row.querySelector('[data-piecework-order-product]');
            const taskSelect = row.querySelector('[data-piecework-task-assignment]');
            if (orderSelect) {
                orderSelect.value = '';
            }
            if (productSelect) {
                productSelect.value = '';
            }
            if (taskSelect) {
                taskSelect.value = '';
            }
            row.querySelector('[data-piecework-product-name]').value = '';
            row.querySelector('[data-piecework-assignment-id]').value = '';
            list.append(row);
            initializeDeadlineControl(deadlineControl);
            updateRows();
        });

        updateRows();
    });
};

const initializeFactoryPieceworkAssignmentForms = () => {
    document.querySelectorAll('[data-factory-piecework-assignment-form]').forEach((form) => {
        if (form.dataset.factoryPieceworkInitialized === 'true') {
            return;
        }

        form.dataset.factoryPieceworkInitialized = 'true';
        const list = form.querySelector('[data-factory-piecework-list]');
        const addButton = form.querySelector('[data-factory-piecework-add]');
        const availableProductCount = list.querySelector('[data-factory-piecework-product]')
            .querySelectorAll('option[value]:not([value=""])').length;

        const updateRows = () => {
            const rows = [...list.querySelectorAll('[data-factory-piecework-item]')];
            const selectedProducts = new Set(rows
                .map((row) => row.querySelector('[data-factory-piecework-product]').value)
                .filter(Boolean));

            rows.forEach((row, index) => {
                row.querySelectorAll('[data-factory-piecework-field]').forEach((input) => {
                    input.name = `assignments[${index}][${input.dataset.factoryPieceworkField}]`;
                });

                const productSelect = row.querySelector('[data-factory-piecework-product]');
                const quantityInput = row.querySelector('[data-factory-piecework-quantity]');
                const availableOutput = row.querySelector('[data-factory-piecework-available]');
                const selectedOption = productSelect.selectedOptions[0];
                const deadlineControl = row.querySelector('[data-deadline-control]');
                const deadlineDaysInput = deadlineControl.querySelector('[data-deadline-days]');
                const maxDeadline = deadlineControl.querySelector('[data-deadline-date]').max;

                if (selectedOption?.value) {
                    const availableQuantity = Number(selectedOption.dataset.remaining) || 0;
                    quantityInput.max = String(availableQuantity);
                    if (Number(quantityInput.value) > availableQuantity) {
                        quantityInput.value = String(availableQuantity);
                    }
                    availableOutput.textContent = `Sisa yang dapat ditugaskan: ${availableQuantity} unit.`;
                } else {
                    quantityInput.removeAttribute('max');
                    availableOutput.textContent = 'Pilih produk untuk melihat jumlah yang tersedia.';
                }

                if (maxDeadline) {
                    const maxDays = (utcDateValue(maxDeadline) - utcDateValue(deadlineControl.dataset.deadlineToday))
                        / (24 * 60 * 60 * 1000);
                    deadlineDaysInput.max = String(maxDays);
                } else {
                    deadlineDaysInput.removeAttribute('max');
                }

                row.querySelector('[data-factory-piecework-remove]').disabled = rows.length === 1;
            });

            rows.forEach((row) => {
                const productSelect = row.querySelector('[data-factory-piecework-product]');
                const selectedProductId = productSelect.value;
                productSelect.querySelectorAll('option[value]:not([value=""])').forEach((option) => {
                    option.disabled = selectedProducts.has(option.value) && option.value !== selectedProductId;
                });
            });

            addButton.disabled = rows.length >= availableProductCount;
        };

        list.addEventListener('change', (event) => {
            if (event.target.matches('[data-factory-piecework-product]')) {
                updateRows();
            }
        });

        list.addEventListener('input', (event) => {
            if (event.target.matches('[data-factory-piecework-price]')) {
                formatMoneyInput(event.target);
            }
        });

        list.addEventListener('click', (event) => {
            const removeButton = event.target.closest('[data-factory-piecework-remove]');
            if (!removeButton || list.querySelectorAll('[data-factory-piecework-item]').length === 1) {
                return;
            }

            removeButton.closest('[data-factory-piecework-item]').remove();
            updateRows();
        });

        addButton.addEventListener('click', () => {
            const row = list.querySelector('[data-factory-piecework-item]').cloneNode(true);
            row.querySelector('[data-factory-piecework-product]').value = '';
            row.querySelector('[data-factory-piecework-quantity]').value = '1';
            row.querySelector('[data-factory-piecework-price]').value = '';

            const deadlineControl = row.querySelector('[data-deadline-control]');
            deadlineControl.querySelector('[data-deadline-date]').value = form.dataset.defaultDeadline;
            deadlineControl.querySelector('[data-deadline-days]').value = '';
            delete deadlineControl.dataset.deadlineInitialized;

            list.append(row);
            initializeDeadlineControl(deadlineControl);
            updateRows();
        });

        list.querySelectorAll('[data-deadline-control]').forEach(initializeDeadlineControl);
        updateRows();
    });
};

const initializePieceworkForms = () => {
    document.querySelectorAll('[data-deadline-control]').forEach(initializeDeadlineControl);
    initializeAssignmentForms();
    initializePieceworkItems();
    initializeFactoryPieceworkAssignmentForms();
};

document.addEventListener('pilar:navigated', initializePieceworkForms);
initializePieceworkForms();
