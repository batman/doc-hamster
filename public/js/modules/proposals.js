/* Vorschläge (assistant/proposals.blade.php, points 1–7): ✓, the bin, "Das andere behalten" and "N übernehmen" post in
   the background. The toast says what happened; every Vorschläge window reloads in place with its filter and page (A61);
   a document or booking window opened from that proposal (A62, ?proposal=) loses its ✓ / bin; the badges follow.
   Without JavaScript they are plain forms. */
(() => {
    const DocHamster = window.DocHamster;
    if (!DocHamster) {
        return;
    }
    const send = form => fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(response => response.json().then(data => ({ ok: response.ok, data })));
    const reloadLists = () => document.querySelectorAll('section[data-window][data-window-kind="proposals"]').forEach(win => {
        DocHamster.reloadWindow(win, new URL(win.dataset.windowUrl ?? location.href, location.href), '.win-frame').catch(() => {});
    });

    document.addEventListener('submit', event => {
        const form = event.target.closest?.('form[data-proposal-action]');
        if (!form || !form.closest('section[data-window]') || !DocHamster.reloadWindow) {
            return;
        }
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        button?.setAttribute('disabled', '');
        send(form).then(({ ok, data }) => {
            DocHamster.toast?.(data.message, { error: !ok || data.error });
            const id = form.dataset.proposalAction;
            if (id) {
                document.querySelectorAll(`[data-proposal-head="${CSS.escape(id)}"]`).forEach(el => el.remove());
            }
            reloadLists();
            DocHamster.emit('bank:changed');
            DocHamster.refreshBadges?.();
        }).catch(() => form.submit()).finally(() => button?.removeAttribute('disabled'));
    });
})();
