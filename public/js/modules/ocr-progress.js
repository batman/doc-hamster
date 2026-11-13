/* Package G */
// Text recognition progress (points 35/43, A21-A24, 57): the OCR indicator below Abmelden polls while documents are open,
// the Texterkennung page every 3 s while something waits or runs; the browser fallback reports its percent.
(() => {
    const getJson = url => fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(response => (response.ok ? response.json() : null))
        .catch(() => null);
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const PAUSE_KEY = 'dh.ocr.paused';
    const isPaused = () => {
        try { return localStorage.getItem(PAUSE_KEY) === '1'; } catch { return false; }
    };

    // Point 57: OCR indicator below Abmelden – open count, small bar, pause per browser; hidden while nothing is open.
    const showNav = data => {
        const nav = document.querySelector('[data-ocr-nav]');
        if (!nav || !data) {
            return;
        }
        const open = data.open ?? data.waiting ?? 0;
        nav.dataset.ocrOpen = String(open);
        const paused = isPaused() && !!nav.querySelector('[data-ocr-toggle]');
        const count = nav.querySelector('[data-ocr-open-text]');
        const pausedLabel = nav.dataset.pausedLabel ?? '';
        if (count) {
            count.textContent = (nav.dataset.label ?? '').replace(':count', String(open)) + (paused ? ` · ${pausedLabel}` : '');
        }
        // A61: why the browser engine does not start.
        if (data.total !== undefined) {
            nav.dataset.engineError = data.engine_error ? (nav.dataset.engineErrorLabel ?? '').replace(':reason', data.engine_error) : '';
        }
        const engineError = nav.dataset.engineError ?? '';
        const icon = nav.querySelector('[data-ocr-icon]');
        if (icon) {
            const tip = (nav.dataset.tooltipLabel ?? '').replace(':count', String(open)) + (paused ? ` · ${pausedLabel}` : '') + (engineError ? ` · ${engineError}` : '');
            icon.dataset.tooltip = tip;
            icon.setAttribute('aria-label', tip);
        }
        const bar = nav.querySelector('[data-ocr-nav-bar]');
        if (bar && data.total !== undefined) {
            bar.max = Math.max(1, (data.total ?? 0) * Number(nav.dataset.passes ?? 3));
            bar.value = data.passes ?? 0;
        }
        nav.hidden = open === 0;
    };

    const paintPause = () => {
        const nav = document.querySelector('[data-ocr-nav]');
        const toggle = nav?.querySelector('[data-ocr-toggle]');
        if (!toggle) {
            return;
        }
        const paused = isPaused();
        nav.classList.toggle('is-paused', paused);
        const label = paused ? toggle.dataset.resume : toggle.dataset.pause;
        toggle.dataset.tooltip = label ?? '';
        toggle.setAttribute('aria-label', label ?? '');
        toggle.setAttribute('aria-pressed', paused ? 'true' : 'false');
        showNav({ open: Number(nav.dataset.ocrOpen ?? 0) });
    };

    const setupNav = () => {
        const nav = document.querySelector('[data-ocr-nav]');
        if (!nav) {
            return;
        }
        paintPause();
        nav.querySelector('[data-ocr-toggle]')?.addEventListener('click', () => {
            const paused = !isPaused();
            try {
                if (paused) localStorage.setItem(PAUSE_KEY, '1');
                else localStorage.removeItem(PAUSE_KEY);
            } catch { /* this tab only */ }
            paintPause();
            document.dispatchEvent(new CustomEvent('dh:ocr-pause', { detail: paused }));
            // Point 61: the OCR log notes who paused or resumed (only the tab that was clicked).
            const logUrl = document.body?.dataset.ocrLog;
            if (logUrl) {
                const body = new FormData();
                body.append('event', paused ? 'paused' : 'resumed');
                fetch(logUrl, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
                    .catch(() => { /* only a log */ });
            }
        });
        // Another tab paused or resumed.
        window.addEventListener('storage', event => { if (event.key === PAUSE_KEY) paintPause(); });
        if (document.querySelector('[data-ocr-page]')) {
            return;
        }
        let timer = null;
        let last = 0;
        const refresh = () => {
            last = Date.now();
            getJson(nav.dataset.ocrNav).then(data => {
                if (!data) {
                    return;
                }
                showNav(data);
                if (!(data.open ?? data.waiting) && timer) {
                    clearInterval(timer);
                    timer = null;
                }
            });
        };
        const poll = () => {
            if (!timer) {
                timer = setInterval(() => { if (!document.hidden) refresh(); }, 10000);
            }
        };
        if (Number(nav.dataset.ocrOpen) > 0) {
            poll();
        }
        // The browser fallback finished a document: show it soon, at most every 3 s.
        document.addEventListener('dh:ocr-job', event => {
            if (event.detail === null && Date.now() - last > 3000) {
                refresh();
                poll();
            }
        });
    };

    // Per page instance (Einstellungen → Texterkennung or the module of its own, point 123); polls only while connected.
    const pages = new WeakSet();
    const setupPage = page => {
        if (pages.has(page)) {
            return;
        }
        pages.add(page);
        const box = page.querySelector('[data-ocr-progress]');
        const bar = page.querySelector('[data-ocr-bar]');
        const text = page.querySelector('[data-ocr-progress-text]');
        const now = page.querySelector('[data-ocr-current]');
        const show = data => {
            const counts = data.counts ?? {};
            Object.entries(counts).forEach(([key, value]) => {
                const el = page.querySelector(`[data-ocr-count="${key}"]`);
                if (el) {
                    el.textContent = String(value);
                }
            });
            const done = counts.done ?? 0;
            const total = done + (counts.waiting ?? 0);
            const busy = (counts.waiting ?? 0) > 0 || (data.open ?? 0) > 0 || !!data.current;
            box.hidden = !busy;
            bar.max = Math.max(1, total);
            bar.value = done;
            text.textContent = (page.dataset.progressLabel ?? '').replace(':done', String(done)).replace(':total', String(total));
            const current = data.current;
            now.hidden = !current;
            if (current) {
                const withPercent = current.percent !== null && current.percent !== undefined;
                const label = withPercent ? page.dataset.currentPercentLabel : page.dataset.currentLabel;
                now.textContent = (label ?? '').replace(':percent', String(current.percent)).replace(':title', current.title);
            }
            showNav(data);
            // Point 124: the Live card takes the same answer (status JSON with live=1).
            window.DocHamster?.emit('ocr:status', { page, data });
            return busy;
        };
        let busy = page.dataset.ocrBusy === '1';
        // The Live card keeps polling while it is open, also when nothing waits (a new upload starts the reading).
        const live = () => !!page.querySelector('[data-ocr-live]:not(.is-folded)');
        const tick = () => {
            if (!page.isConnected) {
                return;
            }
            if (!busy && !live()) {
                setTimeout(tick, 3000);
                return;
            }
            if (document.hidden) {
                setTimeout(tick, 3000);
                return;
            }
            getJson(page.dataset.ocrPage).then(data => {
                if (data) {
                    busy = show(data);
                }
                setTimeout(tick, 3000);
            });
        };
        setTimeout(tick, 3000);
    };

    // A65/A68: "Erkennung auf dem Server einrichten" runs in the request; a progress dialog (not closable) meanwhile.
    const setupServerOcr = () => {
        const form = document.querySelector('form[data-ocr-setup]');
        const dialog = document.querySelector('[data-ocr-setup-progress]');
        if (!form || !dialog) {
            return;
        }
        form.addEventListener('submit', event => {
            event.preventDefault();
            dialog.hidden = false;
            const fail = () => {
                const bar = dialog.querySelector('[data-progress-bar]');
                if (bar) bar.hidden = true;
                const step = dialog.querySelector('[data-progress-step]');
                if (step) step.textContent = dialog.dataset.failed ?? '';
                const actions = dialog.querySelector('[data-progress-actions]');
                if (actions) actions.hidden = false;
            };
            dialog.querySelector('[data-progress-close]')?.addEventListener('click', () => location.reload(), { once: true });
            fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(response => (response.ok ? location.reload() : fail()))
                .catch(fail);
        });
    };

    // A69/A74: without cron (body[data-queue-tick]) the open, visible app works off the queue, one job per call:
    // every 10 s while jobs wait, every minute otherwise; stops once the server reports cron.
    const setupQueueTick = () => {
        const url = document.body?.dataset.queueTick;
        if (!url) {
            return;
        }
        const tick = () => {
            if (document.hidden) {
                setTimeout(tick, 10000);
                return;
            }
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
                .then(response => (response.ok ? response.json() : null))
                .catch(() => null)
                .then(data => {
                    if (data?.cron) {
                        return;
                    }
                    setTimeout(tick, data && data.waiting > 0 ? 10000 : 60000);
                });
        };
        setTimeout(tick, 10000);
    };

    // Browser fallback: send the percent of the current document at most every 2 s.
    const setupBrowserProgress = () => {
        let job = null;
        let last = 0;
        let at = null;
        document.addEventListener('dh:ocr-job', event => {
            job = event.detail;
            last = 0;
            at = null;
        });
        // Point 124: page x of y goes along, so other tabs and the server mode of the Live card see it.
        window.DocHamster?.on('ocr:page-start', detail => {
            at = detail;
            last = 0;
        });
        document.addEventListener('dh:ocr-progress', event => {
            const m = event.detail;
            if (!job?.progress || m?.status !== 'recognizing text' || typeof m.progress !== 'number') {
                return;
            }
            const time = Date.now();
            if (time - last < 2000) {
                return;
            }
            last = time;
            const body = new FormData();
            body.append('percent', String(Math.round(m.progress * 100)));
            if (at && at.id === job.id) {
                body.append('page', String(at.n));
                body.append('pages', String(at.pages));
            }
            fetch(job.progress, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
                .catch(() => { /* only a progress hint */ });
        });
    };

    const start = () => {
        setupNav();
        window.DocHamster?.on('window:open', root => root.querySelectorAll?.('[data-ocr-page]').forEach(setupPage));
        document.querySelectorAll('[data-ocr-page]').forEach(setupPage);
        setupBrowserProgress();
        setupServerOcr();
        setupQueueTick();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
