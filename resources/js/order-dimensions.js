document.addEventListener('input', (event) => {
    const input = event.target;

    if (!(input instanceof HTMLInputElement) || !input.matches('[data-furniture-dimensions]')) {
        return;
    }

    const parts = input.value.trim().split(/\s*(?:x|×)\s*|\s+/i).filter(Boolean);

    if (parts.length === 3 && parts.every((part) => /^\d+(?:[.,]\d+)?$/.test(part))) {
        input.value = parts.join('x');
    }
});
