// Point 115, batch 16: errors in the browser go to Einstellungen → Log ("Allgemein") – uncaught errors, failed promises
// and module errors the event bus caught. They are a "Hinweis" (no badge) unless a request failed on the server or a
// person's action did not complete ("Kritisch": reportError(text, where, true), or a promise that failed with a 5xx
// response). Harmless browser noise (ResizeObserver loop warnings, browser extensions) is dropped. Only signed-in pages
// (the route needs a session); at most five per page load and each text once, so a loop can't flood the log. Never
// sends page content, only the message.
(() => {
    const sent = new Set();
    // A view transition the browser skipped is noise too: the page itself changed as it should.
    const noise = [/ResizeObserver loop/i, /(chrome|moz|safari(-web)?)-extension:\/\//i, /Transition was (aborted|skipped)/i];
    const report = (message, where = '', critical = false) => {
        const text = String(message ?? '').slice(0, 480);
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const url = document.querySelector('meta[name="log-client"]')?.content;
        if (!text || !token || !url || sent.size >= 5 || sent.has(text) || noise.some(pattern => pattern.test(`${text} ${where}`))) {
            return;
        }
        sent.add(text);
        const body = new FormData();
        body.set('message', text);
        body.set('where', String(where).slice(0, 280));
        if (critical) {
            body.set('critical', '1');
        }
        fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' } }).catch(() => {});
    };
    const short = file => String(file ?? '').split('/').pop().split('?')[0];
    window.addEventListener('error', event => {
        if (event.message && !/^(chrome|moz|safari(-web)?)-extension:/.test(event.filename ?? '')) {
            report(event.message, `${short(event.filename)}:${event.lineno ?? 0}`);
        }
    });
    window.addEventListener('unhandledrejection', event => {
        const reason = event.reason;
        if (reason instanceof Response) {
            report(`HTTP ${reason.status}: ${short(reason.url)}`, 'Promise', reason.status >= 500);
            return;
        }
        report(reason instanceof Error ? `${reason.name}: ${reason.message}` : reason, 'Promise');
    });
    window.DocHamster.reportError = report;
})();
