const initializeSidebarOrder = () => {
    const form = document.querySelector('[data-sidebar-order-form]');

    if (!form || form.dataset.sidebarOrderInitialized === 'true') {
        return;
    }

    form.dataset.sidebarOrderInitialized = 'true';
    const status = form.querySelector('[data-sidebar-order-status]');
    let draggedItem = null;
    let draggedGroup = null;

    const updateOrder = (message = '') => {
        const groups = Array.from(form.querySelectorAll('[data-sidebar-order-group]'));
        groups.forEach((group, index) => {
            group.querySelector('[data-sidebar-group-position]').textContent = String(index + 1);
            group.querySelectorAll('[data-sidebar-group-move]').forEach((button) => {
                const isFirst = index === 0;
                const isLast = index === groups.length - 1;
                button.disabled = button.dataset.sidebarGroupMove === 'up' ? isFirst : isLast;
            });
        });

        let position = 0;

        form.querySelectorAll('[data-sidebar-order-item]').forEach((item) => {
            position += 1;
            item.querySelector('[data-sidebar-order-input]').value = position;
            item.querySelector('[data-sidebar-order-position]').textContent = String(position);
        });

        form.querySelectorAll('[data-sidebar-move]').forEach((button) => {
            const item = button.closest('[data-sidebar-order-item]');
            const isFirst = !item.previousElementSibling;
            const isLast = !item.nextElementSibling;
            button.disabled = button.dataset.sidebarMove === 'up' ? isFirst : isLast;
        });

        if (status && message) {
            status.textContent = message;
        }
    };

    const moveGroup = (group, direction) => {
        const groups = Array.from(form.querySelectorAll('[data-sidebar-order-group]'));
        const groupIndex = groups.indexOf(group);
        const neighbor = groups[groupIndex + (direction === 'up' ? -1 : 1)];

        if (!neighbor) {
            return false;
        }

        if (direction === 'up') {
            group.parentElement.insertBefore(group, neighbor);
        } else {
            group.parentElement.insertBefore(neighbor, group);
        }

        return true;
    };

    const getDragAfterElement = (list, pointerY) => {
        let closestElement = null;
        let closestOffset = Number.NEGATIVE_INFINITY;

        list.querySelectorAll('[data-sidebar-order-item]:not(.is-dragging)').forEach((item) => {
            const bounds = item.getBoundingClientRect();
            const offset = pointerY - bounds.top - bounds.height / 2;

            if (offset < 0 && offset > closestOffset) {
                closestOffset = offset;
                closestElement = item;
            }
        });

        return closestElement;
    };

    form.addEventListener('dragstart', (event) => {
        const groupHandle = event.target.closest('[data-sidebar-group-drag-handle]');
        const group = groupHandle?.closest('[data-sidebar-order-group]');

        if (group) {
            draggedGroup = group;
            group.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', group.querySelector('.sidebar-order-group-label').textContent.trim());
            return;
        }

        const handle = event.target.closest('[data-sidebar-drag-handle]');
        const item = handle?.closest('[data-sidebar-order-item]');

        if (!item) {
            event.preventDefault();
            return;
        }

        draggedItem = item;
        item.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.querySelector('[data-sidebar-order-input]').name);
    });

    form.addEventListener('dragover', (event) => {
        if (draggedGroup) {
            const targetGroup = event.target.closest('[data-sidebar-order-group]');

            if (!targetGroup || targetGroup === draggedGroup || targetGroup.parentElement !== draggedGroup.parentElement) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            const bounds = targetGroup.getBoundingClientRect();
            const insertBefore = event.clientY < bounds.top + bounds.height / 2
                ? targetGroup
                : targetGroup.nextElementSibling;
            targetGroup.parentElement.insertBefore(draggedGroup, insertBefore);
            updateOrder();
            return;
        }

        const list = event.target.closest('[data-sidebar-order-list]');

        if (!draggedItem || !list || list !== draggedItem.parentElement) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        list.insertBefore(draggedItem, getDragAfterElement(list, event.clientY));
        updateOrder();
    });

    form.addEventListener('drop', (event) => {
        if (draggedGroup) {
            event.preventDefault();
            const label = draggedGroup.querySelector('.sidebar-order-group-label').textContent.trim();
            const position = draggedGroup.querySelector('[data-sidebar-group-position]').textContent;
            updateOrder(`${label} dipindahkan ke urutan kategori ${position}.`);
            return;
        }

        if (!draggedItem) {
            return;
        }

        event.preventDefault();
        const label = draggedItem.querySelector('.sidebar-order-label').textContent.trim();
        const position = draggedItem.querySelector('[data-sidebar-order-position]').textContent;
        updateOrder(`${label} dipindahkan ke urutan ${position}.`);
    });

    form.addEventListener('dragend', () => {
        if (draggedGroup) {
            draggedGroup.classList.remove('is-dragging');
            draggedGroup = null;
            updateOrder();
            return;
        }

        if (!draggedItem) {
            return;
        }

        draggedItem.classList.remove('is-dragging');
        draggedItem = null;
        updateOrder();
    });

    form.addEventListener('click', (event) => {
        const groupButton = event.target.closest('[data-sidebar-group-move]');
        const group = groupButton?.closest('[data-sidebar-order-group]');

        if (groupButton && group && moveGroup(group, groupButton.dataset.sidebarGroupMove)) {
            const label = group.querySelector('.sidebar-order-group-label').textContent.trim();
            const position = group.querySelector('[data-sidebar-group-position]').textContent;
            updateOrder(`${label} dipindahkan ke urutan kategori ${position}.`);
            return;
        }

        const button = event.target.closest('[data-sidebar-move]');
        const item = button?.closest('[data-sidebar-order-item]');

        if (!button || !item) {
            return;
        }

        const list = item.parentElement;
        const neighbor = button.dataset.sidebarMove === 'up'
            ? item.previousElementSibling
            : item.nextElementSibling;

        if (!neighbor) {
            return;
        }

        if (button.dataset.sidebarMove === 'up') {
            list.insertBefore(item, neighbor);
        } else {
            list.insertBefore(neighbor, item);
        }

        updateOrder(`${item.querySelector('.sidebar-order-label').textContent.trim()} dipindahkan ke urutan ${item.querySelector('[data-sidebar-order-position]').textContent}.`);
    });

    form.addEventListener('submit', () => updateOrder());
    updateOrder();
};

document.addEventListener('pilar:navigated', initializeSidebarOrder);
initializeSidebarOrder();
