let filterError = document.getElementById('orders-filter-error');
let activeRequest;

const updateActiveStatusTab = (url) => {
    const selectedStatus = new URL(url, window.location.origin).searchParams.get('status') ?? '';

    document.querySelectorAll('.order-status-tab').forEach((tab) => {
        const tabStatus = new URL(tab.href, window.location.origin).searchParams.get('status') ?? '';
        const isActive = tabStatus === selectedStatus;

        tab.classList.toggle('is-active', isActive);
        if (isActive) {
            tab.setAttribute('aria-current', 'page');
        } else {
            tab.removeAttribute('aria-current');
        }
    });
};

const loadOrderResults = async (url, updateHistory = true) => {
    const currentResults = document.getElementById('order-results');
    if (!currentResults) {
        return;
    }

    activeRequest?.abort();
    activeRequest = new AbortController();
    const request = activeRequest;

    currentResults.setAttribute('aria-busy', 'true');
    if (filterError) {
        filterError.hidden = true;
        filterError.textContent = '';
    }

    try {
        const response = await fetch(url, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html',
            },
            signal: request.signal,
        });

        if (!response.ok) {
            throw new Error('Daftar order gagal dimuat. Silakan coba lagi.');
        }

        const documentFragment = new DOMParser().parseFromString(await response.text(), 'text/html');
        const nextResults = documentFragment.querySelector('#order-results');

        if (!nextResults) {
            throw new Error('Tampilan daftar order tidak dapat diperbarui.');
        }

        currentResults.replaceWith(nextResults);
        updateActiveStatusTab(url);
        if (updateHistory) {
            window.history.pushState({ orderFilter: true }, '', url);
        }
    } catch (error) {
        if (error.name !== 'AbortError' && filterError) {
            filterError.textContent = error.message || 'Daftar order gagal dimuat.';
            filterError.hidden = false;
        }
    } finally {
        if (activeRequest === request) {
            document.getElementById('order-results')?.removeAttribute('aria-busy');
            activeRequest = null;
        }
    }
};

document.addEventListener('pilar:navigated', () => {
    filterError = document.getElementById('orders-filter-error');
});

document.addEventListener('click', (event) => {
    const link = event.target.closest('.order-status-tab, #order-results .pagination a');

    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    event.preventDefault();
    loadOrderResults(link.href);
});

window.addEventListener('popstate', (event) => {
    if (event.state?.orderFilter && document.getElementById('orders-filter-error')) {
        loadOrderResults(window.location.href, false);
    }
});
