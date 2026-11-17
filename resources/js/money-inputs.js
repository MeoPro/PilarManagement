const initializeMoneyInputs = () => {
    document.querySelectorAll('[data-money-input]').forEach((input) => {
        if (input.dataset.moneyInputInitialized === 'true') {
            return;
        }

        const formatCurrency = () => {
            const parts = input.value.replace(/[^\d.]/g, '').split('.');
            const whole = parts.shift() ?? '';
            const fraction = parts.join('').slice(0, 2);

            input.value = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
                + (input.value.includes('.') ? `.${fraction}` : '');
        };

        input.addEventListener('input', formatCurrency);
        input.dataset.moneyInputInitialized = 'true';
        formatCurrency();
    });
};

document.addEventListener('pilar:navigated', initializeMoneyInputs);
initializeMoneyInputs();
