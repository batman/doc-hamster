/* Posteingang (point 153, inbox/index.blade.php): the source tabs, ✓ "Erledigt", "Alle erledigt" and the upload in the
   head work in the background and reload only this window; the menu badges follow at once. Without JavaScript they are
   plain links and forms. */
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

    // 153.4: the head's upload, like the main menu's button; the window shows the new rows right away.
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
