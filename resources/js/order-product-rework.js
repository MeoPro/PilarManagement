const updateStatusPhotoRequirement = (select) => {
    const photoInput = select.closest('tr')?.querySelector('[data-status-photo-input]');
    if (!photoInput) {
        return;
    }

    photoInput.required = select.value !== select.dataset.originalStatus
        && ['Produksi', 'Distribusi'].includes(select.value);
};

document.addEventListener('change', (event) => {
    if (event.target instanceof HTMLSelectElement && event.target.matches('[data-status-photo-select]')) {
        updateStatusPhotoRequirement(event.target);
    }
});

const initializeOrderProductRework = () => {
    document.querySelectorAll('select[data-status-photo-select]').forEach(updateStatusPhotoRequirement);

    document.querySelectorAll('select[data-original-status="Distribusi"]').forEach((select) => {
        const commentField = select.closest('tr').querySelector('[data-rework-comment]');
        const commentInput = commentField?.querySelector('textarea');

        if (!commentField || !commentInput) {
            return;
        }

        const updateCommentRequirement = () => {
            const requiresComment = select.value === 'Produksi';
            commentField.hidden = !requiresComment;
            commentInput.required = requiresComment;
        };

        select.addEventListener('change', updateCommentRequirement);
        updateCommentRequirement();
    });
};

document.addEventListener('pilar:navigated', initializeOrderProductRework);
initializeOrderProductRework();
