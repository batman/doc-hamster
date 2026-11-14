// Fenstertitel umbenennen (points 185.2, A364; <x-window rename-url="…" rename-field="…" :rename-data="[…]">):
// a click on the title (or Enter while it has focus) puts a field of the same size in its place. Enter or leaving the
// field saves at once, Escape or an empty name cancels; no check mark. Afterwards every open window with the same
// rename address gets the new title (head, strip, data-window-title, document.title) and
// DocHamster.emit('window:renamed', { win, name, json }) lets the module update its rows.
(() => {
    const DocHamster = window.DocHamster;
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const note = (text, failed) => {
        if (!text) return;
        const el = document.createElement('div');
        el.className = failed ? 'toast toast-error' : 'toast';
        el.setAttribute('role', failed ? 'alert' : 'status');
        el.textContent = text;
        document.body.append(el);
        const leave = () => { el.classList.add('is-leaving'); setTimeout(() => el.remove(), 300); };
        el.addEventListener('click', leave);
        setTimeout(leave, failed ? 5000 : 2000);
    };

    // A184: the new title in every open window that matches (head, strip, label, the tab's title when active).
    const retitle = (matches, name) => document.querySelectorAll('section[data-window]').forEach(win => {
        let url;
        try { url = new URL(win.dataset.windowUrl, location.href); } catch { return; }
        if (!matches(url, win)) return;
        win.dataset.windowTitle = name;
        win.setAttribute('aria-label', name);
        win.querySelectorAll(':scope .win-title').forEach(h => { h.textContent = name; h.title = name; });
        const label = win.querySelector(':scope > .win-strip .strip-label > span');
        if (label) label.textContent = name;
        if (win.classList.contains('is-active')) {
            document.title = `${name} – ${document.title.split(' – ').pop()}`;
        }
    });

    const start = title => {
        const win = title.closest('section[data-window]');
        if (!win || title.hidden || title.classList.contains('is-saving')) return;
        const old = title.textContent.trim();
        const field = document.createElement('input');
        field.className = 'win-title win-title-field';
        field.value = old;
        field.maxLength = Number(title.dataset.renameMax || 250);
        field.setAttribute('aria-label', title.dataset.renameLabel ?? '');
        title.hidden = true;
        title.after(field);
        field.focus();
        field.select();
        // The head of a detail window drags its document: not while text is selected in the field.
        field.addEventListener('pointerdown', event => event.stopPropagation());
        let done = false;
        const finish = (save, refocus) => {
            if (done) return;
            done = true;
            const name = field.value.trim();
            field.remove();
            title.hidden = false;
            if (refocus) title.focus({ preventScroll: true });
            if (!save || name === '' || name === old) return;
            title.textContent = name;
            title.classList.add('is-saving');
            const body = new FormData();
            try {
                Object.entries(JSON.parse(title.dataset.renameData || '{}')).forEach(([key, value]) => body.append(key, value));
            } catch { /* no extra fields */ }
            body.append(title.dataset.renameField || 'name', name);
            fetch(title.dataset.windowRename, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
                .then(async response => {
                    const json = await response.json().catch(() => ({}));
                    return response.ok ? json : Promise.reject(new Error(response.status === 422 && json.message ? json.message : ''));
                })
                .then(json => {
                    const url = title.dataset.windowRename;
                    retitle((_, other) => other.querySelector(':scope .win-title[data-window-rename]')?.dataset.windowRename === url, name);
                    note(json.message, false);
                    DocHamster?.emit('window:renamed', { win, name, json });
                })
                .catch(error => {
                    title.textContent = old;
                    note(error?.message || title.dataset.renameFailed, true);
                })
                .finally(() => title.classList.remove('is-saving'));
        };
        field.addEventListener('blur', () => finish(true, false));
        field.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                finish(true, true);
            } else if (event.key === 'Escape') {
                // First, before the window or a dialog reacts to the same key.
                event.preventDefault();
                event.stopPropagation();
                finish(false, true);
            }
        });
    };

    document.addEventListener('click', event => {
        const title = event.target.closest?.('.win-title[data-window-rename]');
        if (title) start(title);
    });
    document.addEventListener('keydown', event => {
        const title = event.target.closest?.('.win-title[data-window-rename]');
        if (title && event.key === 'Enter') {
            event.preventDefault();
            start(title);
        }
    });

    if (DocHamster) DocHamster.retitle = retitle;
})();
