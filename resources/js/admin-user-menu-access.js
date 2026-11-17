const initializeAdminUserMenuAccess = () => {
    const unitSelect = document.getElementById('business_unit');

    if (unitSelect) {
        const menuSections = document.querySelectorAll('[data-menu-unit]');
        const emptyMessage = document.querySelector('[data-menu-unit-empty]');

        const updateMenuSections = () => {
            let selectedSectionExists = false;

            menuSections.forEach((section) => {
                const isSelected = section.dataset.menuUnit === unitSelect.value;
                section.hidden = !isSelected;
                if (isSelected) {
                    selectedSectionExists = true;
                }

                section.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                    input.disabled = !isSelected;
                });
            });

            if (emptyMessage) {
                emptyMessage.hidden = selectedSectionExists;
            }
        };

        unitSelect.addEventListener('change', updateMenuSections);
        updateMenuSections();
    }
};

document.addEventListener('pilar:navigated', initializeAdminUserMenuAccess);
initializeAdminUserMenuAccess();
