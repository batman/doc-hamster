// Installable app (point 151): registers the service worker (public/sw.js) named by the manifest link in the layout.
// The worker only catches "Teilen → DocHamster" (POST /share) and keeps the shared files until they are sent.
(() => {
    if (!('serviceWorker' in navigator)) {
        return;
    }
    addEventListener('load', () => {
        const url = document.querySelector('link[rel="manifest"][data-sw]')?.dataset.sw;
        if (url) {
            navigator.serviceWorker.register(url).catch(() => {});
        }
    });
})();
