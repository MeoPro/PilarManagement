document.addEventListener('submit', async (event) => {
    if (!(event.target instanceof HTMLFormElement) || !event.target.matches('[data-order-comment-form]')) {
        return;
    }

    const form = event.target;
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
        submitButton.textContent = 'Mengirim...';
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
