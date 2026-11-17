const reminderModal = document.getElementById('order-comment-reminder');

if (reminderModal) {
    const reminderList = reminderModal.querySelector('[data-reminder-list]');
    const closeReminderModal = () => {
        reminderModal.hidden = true;
    };

    reminderModal.addEventListener('click', (event) => {
        if (event.target === reminderModal || event.target.closest('[data-dismiss-reminders]')) {
            closeReminderModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !reminderModal.hidden) {
            closeReminderModal();
        }
    });

    fetch(reminderModal.dataset.remindersUrl, {
        headers: { Accept: 'application/json' },
    })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`Failed to load order chat reminders (HTTP ${response.status}).`);
            }

            return response.json();
        })
        .then(({ reminders }) => {
            if (!Array.isArray(reminders) || reminders.length === 0) {
                return;
            }

            reminders.forEach((reminder) => {
                const item = document.createElement('li');
                const details = document.createElement('div');
                const title = document.createElement('strong');
                const preview = document.createElement('p');
                const metadata = document.createElement('small');
                const link = document.createElement('a');
                const unit = reminder.business_unit === 'factory' ? 'Pilar Factory' : 'Pilar Bali';
                const replyStatus = reminder.has_replies ? 'Sudah dibalas' : 'Belum dibalas';

                title.textContent = `Order ${reminder.order_id}`;
                preview.textContent = reminder.body;
                metadata.textContent = `${reminder.sender} · ${unit} · ${reminder.created_at} · ${replyStatus}`;
                link.href = reminder.url;
                link.textContent = 'Buka chat';

                details.append(title, preview, metadata);
                item.append(details, link);
                reminderList.appendChild(item);
            });

            reminderModal.hidden = false;
            reminderModal.querySelector('[data-dismiss-reminders]').focus();
        })
        .catch((error) => {
            console.error('Unable to load order chat reminders.', error);
        });
}
