/* Documents and folders: rows, drag and drop, uploads by drop (points 75-87, A131-A180) */
// Split by responsibility (A315): this file holds the shared helpers (DocHamster.docs); documents-rows.js (row icons),
// documents-forms.js (properties modules, field with symbol), documents-rename.js, documents-trash.js, documents-select.js,
// documents-dnd.js (drag engine) and documents-upload.js (files from the computer) build on it, loaded in that order.
// One module for every Dokumente, folder and Papierkorb window, also for windows loaded later and several side by side:
// - row icons as tall as the two text lines (A144-A146): a tight viewBox from the symbol's own box;
// - row actions (A161, A174): pencil renames in place, the gear opens the properties / details as a window (plain link);
// - Ctrl/Shift picks several document rows (A132); a drag carries all picked ones;
// - documents dropped on a folder row (also in another window) or the free space of a folder window move there (point 75),
//   on the Papierkorb row they go into the trash (76), from the Papierkorb onto a folder they come back there (A135);
//   a toast says what happened and offers "Rückgängig" (A133);
// - folders dropped on the middle of a folder row go into it, between rows they take the new place (78, A153); never into
//   themselves (A138), never too deep (A139, a hint on drop), "Dokumente allgemein" and the Papierkorb stay (79, A168);
//   a folder on the Papierkorb row goes there with its content after a confirm (A134);
// - files from the computer: onto a folder row, a folder window or the menu's drop field (85, A169-A175).
// A drag starts only after a few pixels (A155), with a finger after a short hold; Escape cancels.
(() => {
    const DocHamster = window.DocHamster;
    if (!DocHamster) {
        return;
    }
    const html = document.documentElement;
    const config = () => document.querySelector('[data-dnd]')?.dataset ?? {};
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const fill = (template, values) => Object.entries(values).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value)), template ?? '');
    const docsLabel = count => fill(count === 1 ? config().docsOne : config().docsMany, { count });
    const still = matchMedia('(prefers-reduced-motion: reduce)');

    // --- Toast with an optional "Rückgängig" (A133) -------------------------------------------------------------
    const toast = (message, { error = false, undo = null } = {}) => {
        const el = document.createElement('div');
        el.className = error ? 'toast toast-error' : 'toast';
        el.setAttribute('role', error ? 'alert' : 'status');
        const textEl = document.createElement('span');
        textEl.textContent = message;
        el.append(textEl);
        const leave = () => {
            el.classList.add('is-leaving');
            setTimeout(() => el.remove(), 300);
        };
        if (undo) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button-quiet button-small toast-action';
            button.textContent = config().undo ?? 'Undo';
            button.addEventListener('click', event => {
                event.stopPropagation();
                leave();
                undo();
            }, { once: true });
            el.append(button);
        }
        el.addEventListener('click', leave);
        document.body.append(el);
        setTimeout(leave, undo ? 8000 : 4000);
    };
    DocHamster.toast = toast;

    const post = (url, data) => {
        const body = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) {
            Object.entries(data ?? {}).forEach(([key, value]) => {
                if (Array.isArray(value)) {
                    value.forEach(item => body.append(`${key}[]`, item));
                } else if (value !== null && value !== undefined) {
                    body.append(key, value);
                }
            });
        }
        return fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' } })
            .then(async response => {
                const json = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(json.message || config().failed || String(response.status));
                }
                return json;
            });
    };
    const fail = error => toast(error?.message || config().failed, { error: true });

    // Every Dokumente, folder and Papierkorb window loads its body again: counts, rows and names follow.
    const refresh = () => document.querySelectorAll('[data-windows] > section[data-window]').forEach(win => {
        if (['folder', 'trash', 'inbox'].includes(win.dataset.windowKind) && win.querySelector('.win-body')) {
            DocHamster.reloadWindow(win, new URL(win.dataset.windowUrl ?? location.href, location.href), '.win-body').catch(() => {});
        }
    });

    // Our own confirm (framework dialog, built here because the question depends on what was dropped).
    const confirmDialog = ({ title, text, ok }) => new Promise(resolve => {
        const backdrop = document.createElement('div');
        backdrop.className = 'dialog-backdrop';
        backdrop.hidden = true;
        const box = document.createElement('div');
        box.className = 'dialog';
        box.setAttribute('role', 'alertdialog');
        box.setAttribute('aria-modal', 'true');
        const h = document.createElement('h2');
        h.textContent = title;
        const p = document.createElement('p');
        p.textContent = text;
        const actions = document.createElement('div');
        actions.className = 'dialog-actions';
        const no = document.createElement('button');
        no.type = 'button';
        no.className = 'button-quiet';
        no.textContent = config().cancel ?? 'Cancel';
        const yes = document.createElement('button');
        yes.type = 'button';
        yes.className = 'button-danger';
        yes.textContent = ok;
        actions.append(no, yes);
        box.append(h, p, actions);
        backdrop.append(box);
        document.body.append(backdrop);
        const before = document.activeElement;
        const close = answer => {
            document.removeEventListener('keydown', key, true);
            backdrop.remove();
            before?.focus?.();
            resolve(answer);
        };
        const key = event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close(false);
            }
        };
        no.addEventListener('click', () => close(false));
        yes.addEventListener('click', () => close(true));
        backdrop.addEventListener('click', event => {
            if (event.target === backdrop) {
                close(false);
            }
        });
        document.addEventListener('keydown', key, true);
        backdrop.hidden = false;
        no.focus();
    });

    DocHamster.docs = { html, config, token, fill, docsLabel, still, toast, post, fail, refresh, confirmDialog };
})();
