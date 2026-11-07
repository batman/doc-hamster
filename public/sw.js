// DocHamster on the home screen (PHONE, issue #37): keeps the app's own files
// (styles, scripts, icons) and the "no connection" page on the phone.
// Documents, pages and anything personal are NEVER stored here: every page is
// loaded from the server, and only when that fails the offline page is shown.
// Photos taken offline wait in the page's own storage (public/js/app.js).
'use strict';

const VERSION = new URL(self.location.href).searchParams.get('v') || 'dev';
const CACHE = `dochamster-shell-${VERSION}`;
const SCOPE = new URL(self.registration.scope);
const OFFLINE = new URL('offline', SCOPE).href;
const SHELL = /\/(css|js|img)\/[^?]+\.(css|js|png|svg)$/;

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE)
            .then(cache => cache.add(new Request(OFFLINE, { credentials: 'omit', cache: 'reload' })))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(key => key.startsWith('dochamster-shell-') && key !== CACHE).map(key => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== SCOPE.origin) {
        return;
    }

    // Pages: always the server; without a connection the offline page.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
        return;
    }

    // The app's own files: always the newest from the server; the kept copy only without a connection.
    if (SHELL.test(url.pathname)) {
        event.respondWith(fetch(request).then(response => {
            if (response.ok) {
                const copy = response.clone();
                caches.open(CACHE).then(cache => cache.put(request, copy));
            }
            return response;
        }).catch(() => caches.match(request)));
    }
});
