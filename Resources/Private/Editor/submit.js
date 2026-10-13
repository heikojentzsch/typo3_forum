const pending = new Map();

function restore(form) {
    const state = pending.get(form);
    if (!state) return;
    for (const [button, disabled] of state.buttons) button.disabled = disabled;
    state.placeholder?.remove();
    state.hidden?.remove();
    if (state.submitter) {
        state.submitter.hidden = state.wasHidden;
        if (state.content !== null) state.submitter.innerHTML = state.content;
    }
    if (state.submitter && state.live === null)
        state.submitter.removeAttribute('aria-live');
    else if (state.submitter)
        state.submitter.setAttribute('aria-live', state.live);
    if (state.busy === null) form.removeAttribute('aria-busy');
    else form.setAttribute('aria-busy', state.busy);
    pending.delete(form);
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (
        !(form instanceof HTMLFormElement) ||
        !form.closest('.tx-typo3forum') ||
        form.method.toLowerCase() !== 'post'
    )
        return;
    if (pending.has(form)) {
        event.preventDefault();
        return;
    }
    // Editor loading, native constraints and other form handlers must finish first.
    if (event.defaultPrevented) return;
    const submitter = event.submitter;
    const buttons = Array.from(form.elements).filter(
        (element) =>
            (element instanceof HTMLButtonElement ||
                element instanceof HTMLInputElement) &&
            ['submit', 'image'].includes(element.type),
    );
    const state = {
        buttons: buttons.map((button) => [button, button.disabled]),
        submitter,
        wasHidden: submitter?.hidden,
        live: submitter?.getAttribute('aria-live'),
        content:
            submitter instanceof HTMLButtonElement ? submitter.innerHTML : null,
        busy: form.getAttribute('aria-busy'),
    };
    pending.set(form, state);
    // Disabled controls are omitted from the request: preserve the activated action.
    if (submitter?.name) {
        state.hidden = document.createElement('input');
        state.hidden.type = 'hidden';
        state.hidden.name = submitter.name;
        state.hidden.value = submitter.value;
        form.append(state.hidden);
    }
    buttons.forEach((button) => {
        button.disabled = true;
    });
    form.setAttribute('aria-busy', 'true');
    if (submitter) {
        const label = document.documentElement.lang.startsWith('de')
            ? 'Wird gesendet …'
            : 'Sending …';
        const spinner = document.createElement('span');
        spinner.className = 'forum-submit-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        let display = submitter;
        if (submitter instanceof HTMLInputElement) {
            display = document.createElement('button');
            display.type = 'button';
            display.disabled = true;
            display.className = submitter.className;
            submitter.after(display);
            submitter.hidden = true;
            state.placeholder = display;
        }
        display.replaceChildren(spinner, document.createTextNode(' ' + label));
        display.setAttribute('aria-live', 'polite');
    }
    // A later listener may still cancel submission (e.g. async form validation).
    queueMicrotask(() => {
        if (event.defaultPrevented) restore(form);
    });
});

window.addEventListener('pageshow', () => {
    for (const form of pending.keys()) restore(form);
});
