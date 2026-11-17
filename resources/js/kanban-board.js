const initializeKanbanBoard = () => {
    const kanbanBoard = document.querySelector('[data-kanban-board]');

    if (!kanbanBoard || kanbanBoard.dataset.kanbanInitialized === 'true') {
        return;
    }

    kanbanBoard.dataset.kanbanInitialized = 'true';
    const errorMessage = document.querySelector('[data-kanban-error]');
    let draggedTask = null;

    const updateColumnCount = (column) => {
        const count = column.querySelectorAll('[data-kanban-task]').length;
        const badge = column.closest('.kanban-column')?.querySelector('[data-kanban-count]');

        if (badge) {
            badge.textContent = String(count);
        }
    };

    const showError = (message) => {
        errorMessage.textContent = message;
        errorMessage.hidden = false;
    };

    const updateTaskStatus = async (form, status) => {
        const task = form.closest('[data-kanban-task]');
        const originalStatus = task.dataset.status;
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const button = form.querySelector('button[type="submit"]');

        if (!token) {
            showError('Token keamanan tidak tersedia. Muat ulang halaman lalu coba lagi.');
            return false;
        }

        errorMessage.hidden = true;
        button.disabled = true;
        task.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: new FormData(form),
            });
            const result = await response.json();

            if (!response.ok) {
                const validationMessage = Object.values(result.errors ?? {}).flat()[0];
                throw new Error(validationMessage ?? result.message ?? 'Status tugas gagal diperbarui.');
            }

            const destination = kanbanBoard.querySelector(`[data-kanban-column="${result.task.status}"]`);
            if (!destination) {
                throw new Error('Kolom tujuan tidak ditemukan. Muat ulang halaman untuk menyegarkan papan.');
            }

            task.dataset.status = result.task.status;
            form.querySelector('[data-kanban-status]').value = result.task.status;
            task.querySelector('.kanban-task-status-label').textContent = result.task.status_label;
            destination.append(task);
            updateColumnCount(destination);

            const previousColumn = kanbanBoard.querySelector(`[data-kanban-column="${originalStatus}"]`);
            if (previousColumn && previousColumn !== destination) {
                updateColumnCount(previousColumn);
            }

            if (result.task.status_change) {
                let history = task.querySelector('.kanban-status-history');
                if (!history) {
                    history = document.createElement('div');
                    history.className = 'kanban-status-history';
                    const heading = document.createElement('strong');
                    heading.textContent = 'Riwayat perubahan';
                    history.append(heading);
                    history.append(document.createElement('ul'));
                    task.insertBefore(history, form);
                }

                const item = document.createElement('li');
                const transition = document.createElement('span');
                transition.textContent = `${result.task.status_change.from_status_label} → ${result.task.status_change.to_status_label}`;
                const details = document.createElement('small');
                details.textContent = `${result.task.status_change.changed_by} · ${result.task.status_change.changed_at}`;
                item.append(transition, details);

                if (result.task.status_change.photo_url) {
                    const photoLink = document.createElement('a');
                    photoLink.href = result.task.status_change.photo_url;
                    photoLink.target = '_blank';
                    photoLink.rel = 'noopener';
                    photoLink.textContent = 'Lihat foto';
                    item.append(photoLink);
                }

                history.querySelector('ul').append(item);
            }

            return true;
        } catch (error) {
            form.querySelector('[data-kanban-status]').value = originalStatus;
            showError(error.message || 'Status tugas gagal diperbarui.');
            return false;
        } finally {
            button.disabled = false;
            task.removeAttribute('aria-busy');
        }
    };

    kanbanBoard.addEventListener('dragstart', (event) => {
        const task = event.target.closest('[data-kanban-task]');

        if (!task) {
            return;
        }

        draggedTask = task;
        task.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', task.dataset.statusUrl);
    });

    kanbanBoard.addEventListener('dragend', () => {
        draggedTask?.classList.remove('is-dragging');
        draggedTask = null;
    });

    kanbanBoard.addEventListener('dragover', (event) => {
        if (event.target.closest('[data-kanban-column]')) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        }
    });

    kanbanBoard.addEventListener('drop', (event) => {
        const column = event.target.closest('[data-kanban-column]');

        if (!column || !draggedTask) {
            return;
        }

        event.preventDefault();
        const form = draggedTask.querySelector('[data-kanban-status-form]');
        form.querySelector('[data-kanban-status]').value = column.dataset.kanbanColumn;
        form.requestSubmit();
    });

    kanbanBoard.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-kanban-status-form]');

        if (!form) {
            return;
        }

        event.preventDefault();
        updateTaskStatus(form, form.querySelector('[data-kanban-status]').value);
    });
};

document.addEventListener('pilar:navigated', initializeKanbanBoard);
initializeKanbanBoard();
