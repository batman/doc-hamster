// Point 115: errors in the browser go to Einstellungen → Log ("Allgemein", level error) – uncaught errors, failed
// promises and module errors the event bus caught. Only signed-in pages (the route needs a session); at most five
// per page load and each text once, so a loop can't flood the log. Never sends page content, only the message.
(() => {
    const sent = new Set();
    const report = (message, where = '') => {
        const text = String(message ?? '').slice(0, 480);
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const url = document.querySelector('meta[name="log-client"]')?.content;
        if (!text || !token || !url || sent.size >= 5 || sent.has(text)) {
            return;
        }
        sent.add(text);
        const body = new FormData();
        body.set('message', text);
        body.set('where', String(where).slice(0, 280));
        fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' } }).catch(() => {});
    };
    const short = file => String(file ?? '').split('/').pop().split('?')[0];
    window.addEventListener('error', event => {
        if (event.message) report(event.message, `${short(event.filename)}:${event.lineno ?? 0}`);
    });
    window.addEventListener('unhandledrejection', event => {
        const reason = event.reason;
        report(reason instanceof Error ? `${reason.name}: ${reason.message}` : reason, 'Promise');
    });
    window.DocHamster.reportError = report;
})();
