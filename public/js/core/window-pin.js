/* "Auf Home anheften" (A351): the framework entry in every window's ⋯ menu posts the window's address, title and
   symbol to Home's Schnellzugriff, toasts the answer and emits 'dashboard:pinned' (open Home windows redraw their tiles). */
(() => {
    const DocHamster = window.DocHamster;
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    // An address of this app is pinned as its path, so the tile still works under another host name.
    const local = address => {
        try {
            const url = new URL(address, window.location.href);
            return url.origin === window.location.origin ? url.pathname + url.search + url.hash : url.href;
        } catch {
            return address;
        }
    };

    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-pin-home]');
        if (!button) {
            return;
        }
        const win = button.closest('section[data-window]');
        const body = new FormData();
        body.append('url', local(win?.dataset.windowUrl ?? window.location.href));
        body.append('title', win?.dataset.windowTitle ?? document.title);
        body.append('icon', button.dataset.pinIcon ?? '');
        fetch(button.dataset.pinHome, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' } })
            .then(async response => {
                const json = await response.json().catch(() => ({}));
                DocHamster.toast?.(json.message || button.dataset.failed, { error: !response.ok });
                if (response.ok) {
                    DocHamster.emit('dashboard:pinned', { win });
                }
            })
            .catch(() => DocHamster.toast?.(button.dataset.failed, { error: true }));
    });
})();
