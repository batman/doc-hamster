/* Bank module (points 129, A267–A274) */
// - a statement (CSV or Excel) dropped onto the Bank window, an account row or an account window ([data-statement-drop])
//   is imported: an account that knows its columns imports at once (toast), otherwise the column window opens;
// - forms with data-bank-action (confirm, reject, link, unlink, "kein Beleg nötig") are sent in the background;
// - afterwards, and after documents were dropped onto a booking (documents.js emits 'bank:changed'), every Bank window
//   reloads its body. Several Bank windows side by side work: nothing is looked up by id.
(() => {
    const DocHamster = window.DocHamster;
    if (!DocHamster) {
        return;
    }
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const toast = (text, options) => DocHamster.toast?.(text, options);
    const KINDS = ['bank', 'bank-account', 'bank-transaction', 'bank-receipts'];

    const send = (url, body) => fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' } })
        .then(async response => {
            const json = await response.json().catch(() => ({}));
            if (!response.ok) {
                const first = json.errors ? Object.values(json.errors)[0]?.[0] : null;
                throw new Error(first || json.message || String(response.status));
            }
            return json;
        });

    const reloadAll = () => document.querySelectorAll('[data-windows] > section[data-window]').forEach(win => {
        if (KINDS.includes(win.dataset.windowKind) && win.querySelector('.win-body')) {
            DocHamster.reloadWindow(win, new URL(win.dataset.windowUrl ?? location.href, location.href), '.win-body').catch(() => {});
        }
    });
    DocHamster.on('bank:changed', reloadAll);

    // Opens an address as a window to the right of `win`, like a click on a data-window link inside it.
    const openBeside = (win, url) => {
        const link = document.createElement('a');
        link.href = url;
        link.dataset.window = '';
        link.hidden = true;
        (win?.querySelector('.win-body') ?? document.body).append(link);
        link.click();
        link.remove();
    };

    // --- Forms --------------------------------------------------------------------------------------------------
    document.addEventListener('submit', event => {
        const form = event.target.closest?.('form[data-bank-action]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.classList.add('is-busy');
        }
        send(form.action, new FormData(form))
            .then(json => {
                const dialog = form.closest('[data-dialog]');
                if (dialog) {
                    dialog.hidden = true;
                }
                toast(json.message);
                reloadAll();
            })
            .catch(error => toast(error.message, { error: true }))
            .finally(() => {
                if (button) {
                    button.disabled = false;
                    button.classList.remove('is-busy');
                }
            });
    });

    // --- Statements dropped from the computer (A267) ---------------------------------------------------------
    const carriesFiles = event => [...(event.dataTransfer?.types ?? [])].includes('Files');
    // The row, else the window body, a statement would land on.
    const targetOf = el => {
        if (!(el instanceof Element)) {
            return null;
        }
        const row = el.closest('li[data-statement-drop]');
        if (row) {
            return { el: row, url: row.dataset.statementDrop, account: row.dataset.account ?? null, win: row.closest('section[data-window]') };
        }
        const win = el.closest('section[data-window][data-statement-drop]');
        const body = el.closest('.win-body');
        if (!win || !body || win.classList.contains('is-min')) {
            return null;
        }
        body.dataset.uploadLabel = win.dataset.dropLabel ?? '';
        return { el: body, url: win.dataset.statementDrop, account: win.dataset.account ?? null, win };
    };
    let current = null;
    const mark = el => {
        if (current === el) {
            return;
        }
        current?.classList.remove('is-drop-target', 'is-upload-target');
        current = el;
        current?.classList.add('is-drop-target', 'is-upload-target');
    };
    document.addEventListener('dragover', event => {
        if (!carriesFiles(event)) {
            return;
        }
        const target = targetOf(event.target);
        if (target) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        }
        mark(target?.el ?? null);
    });
    document.addEventListener('dragleave', event => {
        if (carriesFiles(event) && !event.relatedTarget) {
            mark(null);
        }
    });
    document.addEventListener('drop', event => {
        if (!carriesFiles(event)) {
            return;
        }
        const target = targetOf(event.target);
        mark(null);
        if (!target) {
            return;
        }
        event.preventDefault();
        const files = [...event.dataTransfer.files];
        const texts = target.win?.querySelector('[data-bank-texts]')?.dataset ?? document.querySelector('[data-bank-texts]')?.dataset ?? {};
        if (files.length !== 1) {
            toast(texts.dropOne ?? '', { error: true });
            return;
        }
        const body = new FormData();
        body.append('file', files[0]);
        if (target.account) {
            body.append('account', target.account);
        } else {
            body.append('name', files[0].name.replace(/\.[^.]+$/, ''));
        }
        if (texts.importing) {
            toast(texts.importing);
        }
        send(target.url, body)
            .then(json => {
                if (json.columns) {
                    openBeside(target.win, json.columns);
                    return;
                }
                toast(json.message);
                reloadAll();
                if (json.url && target.win?.dataset.windowKind === 'bank') {
                    openBeside(target.win, json.url);
                }
            })
            .catch(error => toast(error.message, { error: true }));
    });
})();
