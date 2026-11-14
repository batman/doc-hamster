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
    // Each row's folder button opens a dropdown; its content (recent folders, the tree) comes from the window's template
    // the first time. A pick files this row – or every picked row of the window when this one is picked too – then the
    // toast "Abgelegt in „X“" offers "Rückgängig" and the window reloads without them.
    document.addEventListener('click', event => {
        const opener = event.target.closest?.('[data-folder-menu]');
        const panel = opener?.parentElement.querySelector(':scope > .dropdown-panel');
        if (!panel || panel.childElementCount > 0) {
            return;
        }
        const tpl = winOf(opener)?.querySelector('template[data-folder-menu-tpl]');
        if (tpl) {
            panel.append(tpl.content.cloneNode(true));
        }
    }, true);

    document.addEventListener('click', event => {
        const toggle = event.target.closest?.('.folder-menu .menu-tree-toggle[aria-expanded]');
        if (!toggle) {
            return;
        }
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        // The labels sit on the menu itself (detail's "Verschieben …", A363) or on the window's template.
        const tpl = toggle.closest('[data-collapse]') ?? winOf(toggle)?.querySelector('template[data-folder-menu-tpl]');
        const label = (open ? tpl?.dataset.collapse : tpl?.dataset.expand) ?? '';
        toggle.setAttribute('aria-expanded', String(open));
        toggle.dataset.tooltip = label;
        toggle.setAttribute('aria-label', `${label}: ${toggle.dataset.name ?? ''}`);
        const branch = toggle.closest('li')?.querySelector(':scope > .menu-tree');
        if (branch) {
            branch.hidden = !open;
        }
    });

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
        const label = input.closest('label');
        label?.classList.add('is-busy');
        send(form, new FormData(form))
            .then(data => {
                DocHamster.toast?.(data.message);
                reload(win);
            })
            .catch(data => {
                label?.classList.remove('is-busy');
                input.value = '';
                DocHamster.toast?.(data?.message ?? form.dataset.failed, { error: true });
            });
    });
})();
