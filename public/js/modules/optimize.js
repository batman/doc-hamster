/* Point 236: Einstellungen → Dokumentgröße optimieren – while files wait, the progress figures (A410: optimiert, warten,
   Platz gespart) and the bar refresh every 5 s from the status JSON. Loaded in <head>: starts on DOMContentLoaded. */
(() => {
    const POLL_MS = 5000;

    const paint = (page, data) => {
        Object.entries(data).forEach(([key, value]) => {
            const out = page.querySelector(`[data-optimize-count="${key}"]`);
            if (out) out.textContent = String(value);
        });
        const bar = page.querySelector('[data-optimize-bar]');
        if (bar) {
            bar.max = Math.max(1, (data.done ?? 0) + (data.waiting ?? 0));
            bar.value = data.done ?? 0;
        }
        const progress = page.querySelector('[data-optimize-progress]');
        if (progress) progress.hidden = (data.waiting ?? 0) === 0;
    };

    const poll = page => {
        if (!page.isConnected) {
            return;
        }
        fetch(page.dataset.optimizePage, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(response => (response.ok ? response.json() : null))
            .then(data => {
                if (!data) return;
                paint(page, data);
                if ((data.waiting ?? 0) > 0) setTimeout(() => poll(page), POLL_MS);
            })
            .catch(() => setTimeout(() => poll(page), POLL_MS * 3));
    };

    const start = root => root.querySelectorAll?.('[data-optimize-page]').forEach(page => {
        if (page.dataset.optimizePolling || page.dataset.busy !== '1') return;
        page.dataset.optimizePolling = '1';
        setTimeout(() => poll(page), POLL_MS);
    });

    document.addEventListener('DOMContentLoaded', () => start(document));
    // Switched on from this page: the work starts by itself, so the figures follow.
    window.DocHamster?.on?.('properties:saved', ({ form }) => {
        const page = form?.closest?.('main, body')?.querySelector('[data-optimize-page]');
        if (page && form.querySelector('[name="on"][type="checkbox"]')?.checked) {
            page.dataset.busy = '1';
            delete page.dataset.optimizePolling;
            start(page.parentElement ?? document);
        }
    });
})();
