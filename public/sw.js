// DocHamster service worker (points 151, 151.3). One job only: "Teilen → DocHamster" on Android posts the shared files to
// /share (manifest share_target). The worker keeps them in IndexedDB and sends the phone to GET /share, where
// js/modules/share.js uploads them with the session's CSRF token – after a login first, if needed. Nothing gets lost:
// an entry is removed only after the server stored it. No caching of pages or files.
const DB = 'dochamster-share';
const STORE = 'shares';

const openDb = () => new Promise((resolve, reject) => {
    const request = indexedDB.open(DB, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE, { autoIncrement: true });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
});

const keep = async entry => {
    const db = await openDb();
    try {
        await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).add(entry);
            tx.oncomplete = resolve;
            tx.onerror = () => reject(tx.error);
            tx.onabort = () => reject(tx.error);
        });
    } finally {
        db.close();
    }
};

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'POST' || request.mode !== 'navigate') {
        return; // the page's own upload (fetch with CSRF) goes straight to the server
    }
    const share = new URL('share', self.registration.scope);
    const url = new URL(request.url);
    if (url.origin !== share.origin || url.pathname !== share.pathname) {
        return;
    }
    event.respondWith((async () => {
        try {
            const form = await request.formData();
            const text = name => String(form.get(name) ?? '');
            const files = form.getAll('files').filter(file => file instanceof File && file.size > 0);
            await keep({ title: text('title'), text: text('text'), url: text('url'), files, at: Date.now() });
            return Response.redirect(share.href + '?kept=1', 303);
        } catch {
            return Response.redirect(share.href + '?kept=0', 303);
        }
    })());
});
