let activeNavigationRequest = null;

const setNavigationLoading = (isLoading) => {
    const indicator = document.querySelector('.navigation-progress');
    if (indicator) {
        indicator.hidden = !isLoading;
    }

    document.body.classList.toggle('is-navigating', isLoading);
    document.querySelector('.page-container')?.setAttribute('aria-busy', String(isLoading));
};

const activatePageScripts = (container) => {
    container.querySelectorAll('script').forEach((currentScript) => {
        const script = document.createElement('script');
        const source = currentScript.textContent;
        const declarations = [...source.matchAll(/^([ \t]*)(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/gm)];
        const topLevelIndent = declarations.length > 0
            ? Math.min(...declarations.map(([match, indentation]) => indentation.length))
            : -1;
        const functionNames = declarations
            .filter(([, indentation]) => indentation.length === topLevelIndent)
            .map(([, , name]) => name);
        const exposeFunctions = [...new Set(functionNames)]
            .map((name) => `window.${name} = ${name};`)
            .join('\n');

        for (const attribute of currentScript.attributes) {
            script.setAttribute(attribute.name, attribute.value);
        }

        script.textContent = `(() => {\n${source}\n${exposeFunctions}\n})();`;
        currentScript.replaceWith(script);
    });
};

const replaceDocumentContent = (parsedDocument, url, updateHistory = true) => {
    const nextContent = parsedDocument.querySelector('.page-container');
    const currentContent = document.querySelector('.page-container');
    const nextSidebar = parsedDocument.querySelector('.sidebar-navigation');
    const currentSidebar = document.querySelector('.sidebar-navigation');

    if (!nextContent || !currentContent || !nextSidebar || !currentSidebar) {
        window.location.assign(url);
        return;
    }

    const sidebarScrollTop = currentSidebar.scrollTop;

    document.dispatchEvent(new Event('pilar:before-navigate'));
    currentContent.replaceWith(nextContent);
    currentSidebar.replaceWith(nextSidebar);
    nextSidebar.scrollTop = sidebarScrollTop;

    for (const selector of ['.mobile-menu-panel', '.sidebar-workspace', '.topbar-actions']) {
        const nextElement = parsedDocument.querySelector(selector);
        const currentElement = document.querySelector(selector);
        if (nextElement && currentElement) {
            currentElement.replaceWith(nextElement);
        }
    }

    const nextHeading = parsedDocument.querySelector('.topbar-context strong');
    const currentHeading = document.querySelector('.topbar-context strong');
    if (nextHeading && currentHeading) {
        currentHeading.textContent = nextHeading.textContent;
    }

    document.title = parsedDocument.title;

    if (updateHistory && window.location.href !== url) {
        window.history.pushState({ pilarNavigation: true }, '', url);
    }

    document.querySelector('.mobile-menu')?.removeAttribute('open');
    window.scrollTo({ top: 0, behavior: 'instant' });
    try {
        activatePageScripts(nextContent);
    } catch (error) {
        console.error('Page scripts failed to initialize after navigation.', error);
    }

    document.dispatchEvent(new Event('pilar:navigated'));
};

const navigateTo = async (url, updateHistory = true) => {
    activeNavigationRequest?.abort();
    activeNavigationRequest = new AbortController();
    const request = activeNavigationRequest;
    setNavigationLoading(true);

    try {
        const response = await fetch(url, {
            headers: {
                Accept: 'text/html',
            },
            signal: request.signal,
        });

        if (!response.ok) {
            throw new Error(`Halaman gagal dimuat (HTTP ${response.status}).`);
        }

        const parsedDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
        replaceDocumentContent(parsedDocument, response.url, updateHistory);
    } catch (error) {
        if (error.name !== 'AbortError') {
            console.error('Menu navigation failed; loading the page normally.', error);
            window.location.assign(url);
        }
    } finally {
        if (activeNavigationRequest === request) {
            activeNavigationRequest = null;
            setNavigationLoading(false);
        }
    }
};

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : event.target.parentElement;
    const link = target?.closest('a[href]');

    if (
        !link
        || event.defaultPrevented
        || event.button !== 0
        || event.metaKey
        || event.ctrlKey
        || event.shiftKey
        || event.altKey
        || link.target
        || link.hasAttribute('download')
        || new URL(link.href, window.location.href).origin !== window.location.origin
    ) {
        return;
    }

    event.preventDefault();
    if (link.href === window.location.href) {
        return;
    }

    const url = new URL(link.href, window.location.href);
    const currentUrl = new URL(window.location.href);
    if (
        url.pathname === currentUrl.pathname
        && url.search === currentUrl.search
        && url.hash !== ''
    ) {
        window.location.hash = url.hash;
        return;
    }

    navigateTo(link.href);
});

document.addEventListener('submit', (event) => {
    if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) {
        return;
    }

    const form = event.target;
    if (form.dataset.spaBypass === 'true') {
        delete form.dataset.spaBypass;
        return;
    }

    if (form.hasAttribute('data-spa-submit')) {
        if (form.getAttribute('target')) {
            return;
        }

        const url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        if (url.origin !== window.location.origin) {
            return;
        }

        event.preventDefault();
        const submitter = event.submitter;
        const formData = submitter
            ? new FormData(form, submitter)
            : new FormData(form);

        activeNavigationRequest?.abort();
        activeNavigationRequest = new AbortController();
        const request = activeNavigationRequest;
        setNavigationLoading(true);

        fetch(url.href, {
            method: (form.getAttribute('method') || 'GET').toUpperCase(),
            body: formData,
            headers: {
                Accept: 'text/html',
            },
            signal: request.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`Pengaturan gagal disimpan (HTTP ${response.status}).`);
                }

                const parsedDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
                replaceDocumentContent(parsedDocument, response.url);
            })
            .catch((error) => {
                if (error.name !== 'AbortError') {
                    console.error('Preference update failed; submitting the form normally.', error);
                    form.dataset.spaBypass = 'true';
                    if (submitter) {
                        form.requestSubmit(submitter);
                    } else {
                        form.requestSubmit();
                    }
                }
            })
            .finally(() => {
                if (activeNavigationRequest === request) {
                    activeNavigationRequest = null;
                    setNavigationLoading(false);
                }
            });
        return;
    }

    if ((form.getAttribute('method') || 'get').toUpperCase() !== 'GET' || form.getAttribute('target')) {
        return;
    }

    const url = new URL(form.getAttribute('action') || window.location.href, window.location.href);
    if (url.origin !== window.location.origin) {
        return;
    }

    const formData = event.submitter
        ? new FormData(form, event.submitter)
        : new FormData(form);
    const query = new URLSearchParams();
    for (const [key, value] of formData.entries()) {
        if (typeof value === 'string') {
            query.append(key, value);
        }
    }

    event.preventDefault();
    url.search = query.toString();
    navigateTo(url.href);
});

window.addEventListener('popstate', (event) => {
    if (event.state?.orderFilter) {
        return;
    }

    navigateTo(window.location.href, false);
});
