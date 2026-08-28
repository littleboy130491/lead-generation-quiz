(() => {
    const form = document.querySelector('[data-quiz-form]');

    if (!form) {
        return;
    }

    const nextButton = form.querySelector('[data-direction-next]');
    const isTextEntry = (element) => element instanceof HTMLElement
        && (element.matches('textarea, select, [contenteditable="true"]')
            || (element.matches('input') && ! element.matches('input[type="radio"], input[type="checkbox"]')));

    document.addEventListener('keydown', (event) => {
        if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        const target = event.target;

        if (/^[a-j]$/i.test(event.key) && !isTextEntry(target)) {
            const option = form.querySelector(`[data-shortcut="${event.key.toUpperCase()}"]`);
            const input = option?.querySelector('input');

            if (input instanceof HTMLInputElement && !input.disabled) {
                event.preventDefault();
                input.click();
                input.focus({ preventScroll: true });
            }

            return;
        }

        if (event.key !== 'Enter' || event.shiftKey || target instanceof HTMLTextAreaElement || target instanceof HTMLButtonElement || target instanceof HTMLAnchorElement) {
            return;
        }

        if (nextButton instanceof HTMLButtonElement && !nextButton.disabled) {
            event.preventDefault();
            form.requestSubmit(nextButton);
        }
    });
})();
