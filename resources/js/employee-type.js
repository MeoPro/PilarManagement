const initializeEmployeeTypeFields = () => {
    document.querySelectorAll('[data-employee-type-select]').forEach((select) => {
        if (select.dataset.employeeTypeInitialized === 'true') {
            return;
        }

        const form = select.closest('form');
        const dailyWage = form?.querySelector('[data-daily-wage-field]');

        if (!dailyWage) {
            return;
        }

        select.dataset.employeeTypeInitialized = 'true';

        const updateDailyWageField = () => {
            const isDaily = select.value === 'Harian';
            dailyWage.required = isDaily;
            dailyWage.disabled = !isDaily;

            if (!isDaily) {
                dailyWage.value = '';
            }
        };

        select.addEventListener('change', updateDailyWageField);
        updateDailyWageField();
    });
};

document.addEventListener('pilar:navigated', initializeEmployeeTypeFields);
initializeEmployeeTypeFields();
