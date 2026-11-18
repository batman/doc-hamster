/* Posteingang (point 153, inbox/index.blade.php): the source tabs, ✓ "Erledigt", "Alle erledigt", "Ablegen in …" (A360)
   and the upload in the head work in the background and reload only this window; the menu badges follow at once.
   Without JavaScript they are plain links and forms. */
(() => {
    const DocHamster = window.DocHamster;
    if (!DocHamster) {
        return;
    }
    const winOf = el => el.closest('section[data-window]');
    const reload = (win, url) => DocHamster.reloadWindow(win, new URL(url ?? win.dataset.windowUrl ?? location.href, location.href), '.win-frame')
        .then(() => DocHamster.refreshBadges?.())
        .catch(() => { location.href = url ?? win.dataset.windowUrl ?? location.href; });
    const send = (form, body) => fetch(form.action, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(response => response.json().then(data => (response.ok ? data : Promise.reject(data))));

    document.addEventListener('click', event => {
        const tab = event.target.closest?.('a[data-inbox-tab]');
        const win = tab && winOf(tab);
        if (!win || !DocHamster.reloadWindow || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }
        event.preventDefault();
        reload(win, tab.href);
    });

    document.addEventListener('submit', event => {
        const form = event.target.closest?.('form[data-inbox-action]');
        const win = form && winOf(form);
        if (!win || !DocHamster.reloadWindow) {
            return;
        }
        event.preventDefault();
        form.querySelector('button')?.setAttribute('disabled', '');
        send(form, new FormData(form))
            .then(data => {
                DocHamster.toast?.(data.message);
                reload(win);
            })
            .catch(() => form.submit());
    });

    // --- "Ablegen in …" (A360, A361) ------------------------------------------------------------------------------
    // Each row's folder button opens the Ordner-Menü (core/folder-menu.js fills it). A pick files this row – or every
    // picked row of the window when this one is picked too – then the toast "Abgelegt in „X“" offers "Rückgängig" and
    // the rows leave. The selection bar's menu (documents-pick.js) does the same for the picked rows.
    document.addEventListener('click', event => {
        const pickFolder = event.target.closest?.('.folder-menu [data-file-into]');
        const row = pickFolder?.closest('[data-doc]');
        const docs = DocHamster.docs;
        const cfg = docs?.config() ?? {};
        if (!row || !cfg.move) {
            return;
        }
        const win = winOf(row);
        const rows = row.classList.contains('is-selected') ? docs.docsIn(win ?? document).filter(each => each.classList.contains('is-selected')) : [row];
        docs.post(cfg.move, { ids: rows.map(each => each.dataset.doc), folder: pickFolder.dataset.fileInto, filed: 1 })
            .then(json => {
                rows.forEach(each => { each.hidden = true; });
                docs.toast(json.message, { undo: () => docs.undoMoves(json.moves ?? []) });
                docs.refresh();
                DocHamster.refreshBadges?.();
            })
            .catch(docs.fail);
    });

    // 153.4: the head's upload ("Hochladen", point 182); new files wait here without a folder, the window shows them.
    document.addEventListener('change', event => {
        const input = event.target.closest?.('form[data-inbox-upload] input[type="file"]');
        const form = input?.form;
        const win = form && winOf(form);
        if (!win || input.files.length === 0 || !DocHamster.reloadWindow) {
            return;
        }
        // Points 27–29: through the Hochlade-Dialog (core/upload.js); the window shows the new files as they arrive.
        const files = [...input.files];
        input.value = '';
        DocHamster.uploadFiles(files, { url: form.action, folder: form.querySelector('[name="folder"]')?.value ?? '', name: win.dataset.windowTitle ?? '', after: () => reload(win) })
            .finally(() => reload(win));
    });
})();
