(() => {
    // ---------------------------------------------------------------------------------------------
    // Window cache and prefetch (point 164.8, A342; docs/design/FRAMEWORK.md § 5 "Vorladen").
    // windows.js loads every window through DocHamster.windowCache.take(url): a window kept in memory opens at once,
    // then refreshes quietly (the server answers 304 while nothing changed, WindowEtag). Filled by
    //  a) hover intent (80 ms) or touchstart on a[data-window],
    //  b) with "Stark": the window links of the active window whenever the browser is idle,
    //  g) always (unless "Aus"): the small windows (menu windows, Dokumente top level, Bank overview) once after sign-in,
    //     kept for the tab session (sessionStorage),
    //  A342) the window this person usually opens next after the current one (learned per user in localStorage).
    // Anything that changes data (a non-GET fetch, a posted form) drops the cache. Slow connection (saveData, 2g) or a
    // slow measured server: hover intent only. The option comes from <body data-prefetch="off|normal|strong">.
    // Main-menu links are full page loads: speculation rules let the browser prefetch them on hover (Chromium).
    // Prefetch requests carry X-DocHamster-Prefetch: 1, so the server marks nothing as seen and records no time.
    // ---------------------------------------------------------------------------------------------
    const DocHamster = window.DocHamster;
    const HEADERS = { 'X-DocHamster-Window': '1', Accept: 'text/html' };
    const MAX = 60;
    const SMALL = 150_000;
    const SESSION = 'dh-wc:';
    const store = new Map();
    const pending = new Map();
    const timings = [];
    const body = () => document.body;
    const level = () => body()?.dataset.prefetch ?? 'off';
    const user = () => body()?.dataset.user ?? '';
    const norm = url => {
        try {
            const u = new URL(url, location.href);
            u.hash = '';
            return u.origin === location.origin ? u.href : null;
        } catch {
            return null;
        }
    };
    // f) adaptive: data saver, a 2g connection or a server slower than 800 ms (median of the last window loads).
    const slow = () => {
        const c = navigator.connection;
        if (c && (c.saveData || /2g$/.test(c.effectiveType ?? ''))) {
            return true;
        }
        const sorted = [...timings].sort((a, b) => a - b);
        return sorted.length >= 3 && sorted[Math.floor(sorted.length / 2)] > 800;
    };
    const serverMs = response => Number(/(?:^|,)\s*total;[^,]*dur=([\d.]+)/.exec(response.headers.get('Server-Timing') ?? '')?.[1] ?? 0);

    const keep = (url, html, small = false) => {
        store.delete(url);
        store.set(url, html);
        while (store.size > MAX) {
            store.delete(store.keys().next().value);
        }
        if (small && html.length < SMALL) {
            try { sessionStorage.setItem(SESSION + user() + ':' + url, html); } catch { /* full: memory only */ }
        }
    };
    const stored = url => {
        if (store.has(url)) {
            return store.get(url);
        }
        try {
            const html = sessionStorage.getItem(SESSION + user() + ':' + url);
            if (html !== null) {
                store.set(url, html);
            }
            return html;
        } catch {
            return null;
        }
    };
    const drop = () => {
        store.clear();
        try {
            Object.keys(sessionStorage).filter(key => key.startsWith(SESSION)).forEach(key => sessionStorage.removeItem(key));
        } catch { /* nothing stored */ }
        warmed = false;
    };

    const request = (url, prefetch = false) => fetch(url, { credentials: 'same-origin', headers: prefetch ? { ...HEADERS, 'X-DocHamster-Prefetch': '1' } : HEADERS })
        .then(response => {
            if (!response.ok) {
                return Promise.reject(response);
            }
            const ms = serverMs(response);
            if (ms > 0) {
                timings.push(ms);
                timings.splice(0, Math.max(0, timings.length - 9));
            }
            return response.text();
        });

    // Prefetch one window (no-op when off, cached or on its way).
    const prefetch = (href, small = false) => {
        const url = norm(href);
        if (!url || level() === 'off' || store.has(url) || pending.has(url)) {
            return;
        }
        const run = request(url, true).then(html => {
            keep(url, html, small);
            return html;
        }).finally(() => pending.delete(url));
        run.catch(() => { /* a failed prefetch only costs the click its head start */ });
        pending.set(url, run);
    };

    // For windows.js: the HTML of a window and whether it came from the cache (then refresh() follows).
    const take = href => {
        const url = norm(href) ?? href;
        const html = stored(url);
        if (html !== null && html !== undefined) {
            return Promise.resolve({ html, cached: true });
        }
        if (pending.has(url)) {
            // A prefetch on its way was sent as prefetch: the real request follows as the refresh.
            return pending.get(url).then(text => ({ html: text, cached: true }), () => take(url));
        }
        return request(url).then(text => {
            keep(url, text);
            return { html: text, cached: false };
        });
    };

    // c) quiet refresh of a window opened from the cache: new content only while the person hasn't touched the window.
    const touched = new WeakSet();
    ['pointerdown', 'keydown', 'input'].forEach(type => document.addEventListener(type, event => {
        const win = event.target.closest?.('section[data-window]');
        if (win) {
            touched.add(win);
        }
    }, { capture: true, passive: true }));
    const refresh = (win, href) => {
        const url = norm(href);
        const before = url ? stored(url) : null;
        if (!url) {
            return;
        }
        request(url).then(html => {
            if (html === before) {
                return;
            }
            keep(url, html);
            if (touched.has(win) || !win.isConnected) {
                return;
            }
            const found = [...new DOMParser().parseFromString(html, 'text/html').querySelectorAll('section[data-window]')].pop();
            const part = win.querySelector(':scope > .win-frame') ? ':scope > .win-frame' : '.win-body';
            const fresh = found?.querySelector(part.replace(':scope > ', ''));
            const old = win.querySelector(part);
            if (!fresh || !old) {
                return;
            }
            const next = document.importNode(fresh, true);
            if (win.dataset.windowKey) {
                DocHamster.isolate?.(next, win.dataset.windowKey);
            }
            old.replaceWith(next);
            DocHamster.emit('window:open', next);
            document.dispatchEvent(new Event('dh:window-url'));
        }).catch(() => { /* the cached content stays */ });
    };

    // A342: which window this person opens after which (per user, localStorage); the usual next one is prefetched.
    const NEXT = () => `dh-next:${user()}`;
    const concrete = url => !/\/[0-9a-hjkmnp-tv-z]{26}(?:[/?]|$)/i.test(new URL(url).pathname + '?');
    const readNext = () => {
        try {
            return JSON.parse(localStorage.getItem(NEXT())) ?? {};
        } catch {
            return {};
        }
    };
    const learn = (fromHref, toHref) => {
        const from = norm(fromHref);
        const to = norm(toHref);
        if (!from || !to || from === to || !concrete(to)) {
            return;
        }
        const next = readNext();
        const row = next[from] ?? {};
        row[to] = (row[to] ?? 0) + 1;
        next[from] = Object.fromEntries(Object.entries(row).sort((a, b) => b[1] - a[1]).slice(0, 5));
        const keys = Object.keys(next);
        keys.slice(0, Math.max(0, keys.length - 200)).forEach(key => delete next[key]);
        try { localStorage.setItem(NEXT(), JSON.stringify(next)); } catch { /* not learned */ }
    };
    const usualNext = fromHref => {
        const row = readNext()[norm(fromHref) ?? ''] ?? {};
        const [best] = Object.entries(row).sort((a, b) => b[1] - a[1]);
        return best && best[1] >= 2 ? best[0] : null;
    };
    const idle = fn => (window.requestIdleCallback ? requestIdleCallback(fn, { timeout: 3000 }) : setTimeout(fn, 600));
    const afterOpen = href => {
        if (level() === 'off' || slow()) {
            return;
        }
        const next = usualNext(href);
        if (next) {
            idle(() => prefetch(next));
        }
    };

    // g) the small windows, once per tab session after sign-in (then quietly refreshed when opened).
    let warmed = false;
    const warm = () => {
        if (warmed || level() === 'off' || slow()) {
            return;
        }
        warmed = true;
        const urls = (body()?.dataset.prefetchSmall ?? '').split(' ').filter(Boolean);
        const step = () => {
            const url = urls.shift();
            if (url) {
                if (stored(norm(url)) === null) {
                    prefetch(url, true);
                }
                idle(step);
            }
        };
        idle(step);
    };

    // b) "Stark": the window links of the active window, a few at a time while idle.
    const eager = root => {
        if (level() !== 'strong' || slow()) {
            return;
        }
        const links = [...(root.querySelectorAll?.('a[data-window][href]') ?? [])].slice(0, 12);
        const step = () => {
            const link = links.shift();
            if (link) {
                prefetch(link.href);
                idle(step);
            }
        };
        idle(step);
    };

    // a) hover intent and touch.
    let hover = 0;
    document.addEventListener('pointerover', event => {
        const link = event.target.closest?.('a[data-window][href]');
        clearTimeout(hover);
        if (link && level() !== 'off') {
            hover = setTimeout(() => prefetch(link.href), 80);
        }
    }, { passive: true });
    document.addEventListener('pointerout', () => clearTimeout(hover), { passive: true });
    document.addEventListener('touchstart', event => {
        const link = event.target.closest?.('a[data-window][href]');
        if (link && level() !== 'off') {
            prefetch(link.href);
        }
    }, { passive: true });

    // Change events: any request that changes data (and every posted form) drops what is kept.
    const QUIET = /\/(queue\/tick|ocr(\/|-log|-engine)|log\/client|badges)/;
    const plainFetch = window.fetch.bind(window);
    window.fetch = (input, init = {}) => {
        const method = (init.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();
        const result = plainFetch(input, init);
        if (method !== 'GET' && method !== 'HEAD' && !QUIET.test(String(input instanceof Request ? input.url : input))) {
            result.then(drop, drop);
        }
        return result;
    };
    document.addEventListener('submit', event => {
        if ((event.target.getAttribute?.('method') ?? 'get').toLowerCase() === 'post') {
            drop();
        }
    }, true);
    ['properties:changed', 'bank:changed', 'ocr-text:changed'].forEach(name => DocHamster.on(name, drop));

    // Main menu: the browser prefetches a page while the pointer rests on its link (speculation rules, Chromium).
    const speculate = () => {
        if (level() === 'off' || !HTMLScriptElement.supports?.('speculationrules')) {
            return;
        }
        const rules = document.createElement('script');
        rules.type = 'speculationrules';
        rules.textContent = JSON.stringify({ prefetch: [{
            where: { and: [{ selector_matches: '.nav-list a[href]' }, { not: { selector_matches: '[data-no-prefetch]' } }] },
            eagerness: level() === 'strong' && !slow() ? 'eager' : 'moderate',
        }] });
        document.head.append(rules);
    };

    DocHamster.windowCache = { take, refresh, prefetch, drop, learn, afterOpen };
    DocHamster.on('window:open', root => {
        if (root?.matches?.('section[data-window]')) {
            eager(root);
        }
    });
    document.addEventListener('DOMContentLoaded', () => {
        if (!body()?.dataset.user) {
            // Signed out: nothing kept from before.
            drop();
            return;
        }
        speculate();
        addEventListener('load', warm, { once: true });
    });
})();
