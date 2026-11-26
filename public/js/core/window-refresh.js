(() => {
    const DocHamster = window.DocHamster;
    // ---------------------------------------------------------------------------------------------
    // Point 39 (A100–A102): open windows follow changes made elsewhere – a print to the Drucker-Eingang, an upload from
    // the phone, another person. core/badges.js asks about once a minute (F25) and emits 'data:changed' when the data
    // stamp moved; then every window loads its body again (DocHamster.reloadWindow) – only if its text changed, with the
    // scroll position kept, new rows fading in (.is-fresh). Never while someone works in it (A101): typing or a changed
    // field, a picked row, an open dialog or menu, dragging, a text selection – that window follows as soon as it is free.
    // A window that should never follow carries data-window-refresh="off".
    // ---------------------------------------------------------------------------------------------
    const waiting = new Set();
    let pressed = false;
    let dragging = false;

    const ROW = '[data-doc], [data-folder], [data-window-row]';
    const rowKey = row => row.dataset.doc ?? row.dataset.folder ?? row.id ?? '';
    const text = node => (node?.textContent ?? '').replace(/\s+/g, ' ').trim();

    const dirty = win => [...win.querySelectorAll('input, textarea, select')].some(field => {
        if (field.type === 'checkbox' || field.type === 'radio') {
            // A picked row (Auswählen) is work in progress; other boxes save at once.
            return field.checked && field.closest(ROW) !== null;
        }
        if (field.tagName === 'SELECT' || field.type === 'hidden' || field.readOnly) {
            return false;
        }
        return field.value !== field.defaultValue;
    });

    const busy = win => {
        const active = document.activeElement;
        if (pressed || dragging || document.hidden) return true;
        if (active && win.contains(active) && active.matches('input, textarea, select, [contenteditable]')) return true;
        if (document.querySelector('[data-dialog]:not([hidden]), dialog[open]')) return true;
        if (win.querySelector('[aria-expanded="true"]')) return true;
        const selection = window.getSelection?.();
        if (selection && !selection.isCollapsed && selection.anchorNode && win.contains(selection.anchorNode)) return true;
        return dirty(win);
    };

    const refresh = win => {
        if (!win.isConnected || win.dataset.windowRefresh === 'off' || !DocHamster.reloadWindow) return;
        if (busy(win)) {
            waiting.add(win);
            return;
        }
        waiting.delete(win);
        const body = win.querySelector('.win-body');
        if (!body) return;
        const before = text(body);
        const known = new Set([...body.querySelectorAll(ROW)].map(rowKey));
        const top = body.scrollTop;
        const url = new URL(win.dataset.windowUrl ?? location.href, location.href);
        fetch(url, { credentials: 'same-origin', headers: { 'X-DocHamster-Window': '1', Accept: 'text/html' } })
            .then(response => (response.ok ? response.text() : Promise.reject(response)))
            .then(html => {
                const found = [...new DOMParser().parseFromString(html, 'text/html').querySelectorAll('section[data-window]')].pop();
                // Nothing to see changed in this window, or someone started working meanwhile: leave it as it is.
                if (!found || text(found.querySelector('.win-body')) === before) return null;
                if (busy(win)) {
                    waiting.add(win);
                    return null;
                }
                return DocHamster.reloadWindow(win, url, '.win-body');
            })
            .then(next => {
                if (!next) return;
                next.scrollTop = top;
                next.querySelectorAll(ROW).forEach(row => {
                    if (known.size > 0 && !known.has(rowKey(row))) {
                        row.classList.add('is-fresh');
                        row.addEventListener('animationend', () => row.classList.remove('is-fresh'), { once: true });
                    }
                });
            })
            .catch(() => { /* offline or signed out: the window keeps what it shows */ });
    };

    const all = () => document.querySelectorAll('section[data-window]').forEach(refresh);
    const retry = () => [...waiting].forEach(refresh);

    DocHamster.on('data:changed', all);
    document.addEventListener('pointerdown', () => { pressed = true; });
    document.addEventListener('pointerup', () => { pressed = false; setTimeout(retry, 300); });
    document.addEventListener('pointercancel', () => { pressed = false; });
    document.addEventListener('dragstart', () => { dragging = true; });
    document.addEventListener('dragend', () => { dragging = false; setTimeout(retry, 300); });
    document.addEventListener('focusout', () => setTimeout(retry, 300));
    document.addEventListener('keyup', event => { if (event.key === 'Escape') setTimeout(retry, 300); });
    // A window waiting since a change is checked again with the next poll anyway; this catches the moment it is free.
    setInterval(() => waiting.size && retry(), 5000);
})();
