function showFormErrors(container, errors, form)
{
    const list = document.createElement('ul');
    let firstInvalidField = null;

    form.querySelectorAll('[aria-invalid="true"]').forEach((field) => {
        field.removeAttribute('aria-invalid');
    });

    Object.entries(errors).forEach(([name, messages]) => {
        const item = document.createElement('li');
        const fieldMessages = Array.isArray(messages) ? messages : [messages];

        item.textContent = fieldMessages.join(' ');
        list.appendChild(item);

        const fieldName = name.replace(/\.(\d+)(?=\.|$)/g, '[$1]').replace(/\.(\w+)/g, '[$1]');
        const field = Array.from(form.elements).find((element) => element.name === fieldName);

        if (field) {
            field.setAttribute('aria-invalid', 'true');
            firstInvalidField ??= field;
        }
    });

    container.replaceChildren(list);
    container.hidden = false;
    firstInvalidField?.focus();
}

const initializeFormValidation = () => {
    document.querySelectorAll('form[data-ajax-validation]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const errorContainer = document.getElementById(form.dataset.validationErrors);
        const submitButtons = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));

        errorContainer.hidden = true;
        errorContainer.replaceChildren();
        form.querySelectorAll('[aria-invalid="true"]').forEach((field) => {
            field.removeAttribute('aria-invalid');
        });
        submitButtons.forEach((button) => {
            button.disabled = true;
        });

        try {
            if (form.dataset.checkOrderIdUrl) {
                const orderId = form.elements.namedItem('order_id').value.trim();
                const checkUrl = new URL(form.dataset.checkOrderIdUrl, window.location.href);
                checkUrl.searchParams.set('order_id', orderId);

                const checkResponse = await fetch(checkUrl, {
                    headers: { Accept: 'application/json' },
                });
                const checkResult = await checkResponse.json();

                if (!checkResponse.ok) {
                    throw new Error(`Gagal memeriksa SPK (HTTP ${checkResponse.status}).`);
                }

                if (!checkResult.available) {
                    showFormErrors(errorContainer, {
                        order_id: [checkResult.message ?? 'SPK sudah digunakan.'],
                    }, form);
                    return;
                }
            }

            const formData = new FormData(form);

            form.querySelectorAll('[data-money-input]').forEach((input) => {
                formData.set(input.name, input.value.replace(/,/g, ''));
            });

            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            if (response.redirected) {
                window.location.assign(response.url);
                return;
            }

            if (response.status === 422) {
                const result = await response.json();
                showFormErrors(errorContainer, result.errors ?? {
                    form: [result.message ?? 'Periksa kembali data yang diisi.'],
                }, form);
                return;
            }

            throw new Error(`Gagal menyimpan data (HTTP ${response.status}). Periksa koneksi dan coba lagi.`);
        } catch (error) {
            console.error(error);
            showFormErrors(errorContainer, {
                form: [error instanceof TypeError
                    ? 'Tidak dapat menghubungi server. Isian form tetap tersimpan, silakan coba lagi.'
                    : error.message],
            }, form);
        } finally {
            submitButtons.forEach((button) => {
                button.disabled = false;
            });
        }
        });
    });
};

document.addEventListener('pilar:navigated', initializeFormValidation);
initializeFormValidation();
