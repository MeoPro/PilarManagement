document.addEventListener('submit', async (event) => {
    if (!(event.target instanceof HTMLFormElement)) {
        return;
    }

    const form = event.target;
    const isEditForm = form.matches('[data-order-comment-edit-form]');
    if (!isEditForm && !form.matches('[data-order-comment-form]')) {
        return;
    }

    const commentList = document.querySelector('[data-order-comment-list]');
    const feedback = form.querySelector('[data-comment-feedback]');
    const errorMessage = form.querySelector('[data-comment-error]');
    const submitButton = form.querySelector('button[type="submit"]');
    const originalButtonLabel = submitButton?.textContent ?? '';

    event.preventDefault();

    if (feedback) {
        feedback.hidden = true;
    }
    if (errorMessage) {
        errorMessage.hidden = true;
    }
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = isEditForm ? 'Menyimpan...' : 'Mengirim...';
    }

    try {
        if (!commentList) {
            throw new Error('Daftar komentar tidak ditemukan. Muat ulang halaman order.');
        }

        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        let payload;

        try {
            payload = await response.json();
        } catch (error) {
            throw new Error(`Komentar gagal dikirim (HTTP ${response.status}).`);
        }

        if (!response.ok) {
            const validationMessage = Object.values(payload.errors ?? {}).flat()[0];
            throw new Error(validationMessage ?? payload.message ?? `Komentar gagal dikirim (HTTP ${response.status}).`);
        }

        const template = document.createElement('template');
        template.innerHTML = payload.html;
        const commentElement = template.content.firstElementChild;

        if (!commentElement) {
            throw new Error('Komentar tersimpan, tetapi tidak dapat ditampilkan. Muat ulang halaman order.');
        }

        if (isEditForm) {
            const commentId = String(payload.comment.id);
            const existingComment = [...commentList.querySelectorAll('[data-order-comment-id]')]
                .find((element) => element.dataset.orderCommentId === commentId);

            if (!existingComment) {
                throw new Error('Komentar diperbarui, tetapi tidak ditemukan pada halaman. Muat ulang halaman order.');
            }

            existingComment.replaceWith(commentElement);
            const successMessage = document.createElement('p');
            successMessage.className = 'message';
            successMessage.setAttribute('role', 'status');
            successMessage.textContent = payload.message;
            commentElement.prepend(successMessage);
            return;
        }

        const parentId = new FormData(form).get('parent_id');

        if (parentId) {
            const thread = [...commentList.querySelectorAll('[data-order-comment-thread]')]
                .find((element) => element.dataset.orderCommentThread === parentId);

            if (!thread) {
                throw new Error('Balasan tersimpan, tetapi diskusi tidak ditemukan. Muat ulang halaman order.');
            }

            let replies = thread.querySelector('[data-order-comment-replies]');
            if (!replies) {
                replies = document.createElement('div');
                replies.className = 'order-comment-replies';
                replies.dataset.orderCommentReplies = '';
                form.before(replies);
            }
            replies.append(commentElement);
            thread.querySelector('[data-comment-reply-status]')?.replaceChildren('Sudah dibalas');
        } else {
            commentList.querySelector('[data-order-comment-empty]')?.remove();
            commentList.append(commentElement);
        }

        form.reset();
        if (feedback) {
            feedback.textContent = payload.message;
            feedback.hidden = false;
        }
    } catch (error) {
        console.error('Order comment submission failed.', error);
        if (errorMessage) {
            errorMessage.textContent = error instanceof Error ? error.message : 'Komentar gagal dikirim. Coba lagi.';
            errorMessage.hidden = false;
        }
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalButtonLabel;
        }
    }
});

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const editButton = event.target.closest('[data-comment-edit-toggle]');
    if (editButton) {
        const comment = editButton.closest('[data-order-comment-id]');
        const editForm = comment?.querySelector('[data-order-comment-edit-form]');
        const commentBody = comment?.querySelector('[data-comment-body]');

        if (editForm && commentBody) {
            editButton.hidden = true;
            commentBody.hidden = true;
            editForm.hidden = false;
            editForm.querySelector('textarea')?.focus();
        }

        return;
    }

    const cancelButton = event.target.closest('[data-comment-edit-cancel]');
    if (!cancelButton) {
        return;
    }

    const comment = cancelButton.closest('[data-order-comment-id]');
    const editForm = comment?.querySelector('[data-order-comment-edit-form]');
    if (!editForm) {
        return;
    }

    editForm.reset();
    editForm.hidden = true;
    editForm.querySelectorAll('[data-comment-feedback], [data-comment-error]').forEach((message) => {
        message.hidden = true;
    });
    const commentBody = comment.querySelector('[data-comment-body]');
    const commentEditButton = comment.querySelector('[data-comment-edit-toggle]');
    if (commentBody) {
        commentBody.hidden = false;
    }
    if (commentEditButton) {
        commentEditButton.hidden = false;
    }
});
