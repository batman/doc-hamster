// "Teilen → DocHamster" (points 151, 151.2, 151.3). The service worker (public/sw.js) kept the shared files in IndexedDB;
// [data-share-replay] (share/receive.blade.php) sends each kept share with the CSRF token, removes it once stored and
// goes on to the confirmation. Logged out, the server sends the phone to the login first and back here afterwards.
// [data-share-finish] on the confirmation closes the page (opened by the share sheet) or returns to the start page.
(() => {
    const DB = 'dochamster-share';
    const STORE = 'shares';

    const openDb = () => new Promise((resolve, reject) => {
        const request = indexedDB.open(DB, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(STORE, { autoIncrement: true });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });

    const entries = db => new Promise((resolve, reject) => {
        const found = [];
        const cursor = db.transaction(STORE).objectStore(STORE).openCursor();
        cursor.onsuccess = () => {
            const at = cursor.result;
            if (!at) {
                resolve(found);
                return;
            }
            found.push({ key: at.key, value: at.value });
            at.continue();
        };
        cursor.onerror = () => reject(cursor.error);
    });

    const remove = (db, key) => new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, 'readwrite');
        tx.objectStore(STORE).delete(key);
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
    });

    const send = async (action, entry, append) => {
        const body = new FormData();
        (entry.files ?? []).forEach(file => body.append('files[]', file, file.name || 'datei'));
        ['title', 'text', 'url'].forEach(name => { if (entry[name]) body.append(name, entry[name]); });
        if (append) body.append('append', '1');
        const response = await fetch(action, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
        });
        if (response.status === 401 || response.status === 419) {
            location.reload(); // session gone: the login comes first, then this page again
            throw new Error('login');
        }
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.json();
    };

    const replay = async box => {
        const message = box.querySelector('[data-share-message]');
        const button = box.querySelector('[data-share-button]');
        const label = button.querySelector('span');
        const busy = on => {
            button.classList.toggle('is-busy', on);
            button.disabled = on;
        };
        busy(true);
        let db;
        try {
            db = await openDb();
            const kept = await entries(db);
            if (kept.length === 0) {
                message.textContent = box.dataset.nothing;
                busy(false);
                label.textContent = box.dataset.finish;
                button.onclick = () => location.replace(box.dataset.home);
                return;
            }
            let answer = null;
            for (const [index, entry] of kept.entries()) {
                answer = await send(box.dataset.shareReplay, entry.value, index > 0);
                await remove(db, entry.key);
            }
            location.replace(answer.redirect);
        } catch (error) {
            if (error.message === 'login') return;
            message.textContent = box.dataset.failed;
            busy(false);
            label.textContent = box.dataset.retry;
            button.onclick = () => replay(box);
        } finally {
            db?.close();
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        const box = document.querySelector('[data-share-replay]');
        if (box && 'indexedDB' in window) {
            replay(box);
        }
    });

    document.addEventListener('click', event => {
        const finish = event.target.closest('[data-share-finish]');
        if (!finish) return;
        window.close();
        setTimeout(() => location.replace(finish.dataset.shareFinish), 300);
    });
})();
