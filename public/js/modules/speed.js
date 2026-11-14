/* Einstellungen → Geschwindigkeit, Selbsttest (point 164.7, settings/speed.blade.php): measured from this browser –
   the empty route (server without app code), the database, the way there and back, a 2 MB download and loading a
   window. The server's own time comes from its Server-Timing header (total). Delegated: the card can come in a window. */
(() => {
    const median = list => [...list].sort((a, b) => a - b)[Math.floor(list.length / 2)];
    const fmt = n => n.toLocaleString(document.documentElement.lang || 'de', { maximumFractionDigits: n < 10 ? 1 : 0 });
    const serverMs = response => {
        const total = /(?:^|,)\s*total;[^,]*dur=([\d.]+)/.exec(response.headers.get('Server-Timing') ?? '');
        return total ? Number(total[1]) : 0;
    };
    const timed = async (url, init = {}) => {
        const start = performance.now();
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...init });
        if (!response.ok && response.status !== 204) {
            throw new Error(String(response.status));
        }
        const body = await response.arrayBuffer();
        return { ms: performance.now() - start, server: serverMs(response), body };
    };
    const text = (card, key, values) => Object.entries(values)
        .reduce((out, [name, value]) => out.replaceAll(`:${name}`, fmt(value)), card.getAttribute(`data-result-${key}`) ?? '');

    const tests = {
        server: async card => {
            const runs = [];
            for (let i = 0; i < 5; i++) {
                runs.push(await timed(card.dataset.ping));
            }
            card.speedPing = runs;
            return text(card, 'server', { server: median(runs.map(r => r.server)), total: median(runs.map(r => r.ms)) });
        },
        db: async card => {
            const { body } = await timed(card.dataset.db, { headers: { Accept: 'application/json' } });
            const ms = JSON.parse(new TextDecoder().decode(body)).ms;
            return ms === null ? Promise.reject(new Error('no database')) : text(card, 'db', { ms });
        },
        roundtrip: async card => text(card, 'roundtrip', { ms: median((card.speedPing ?? []).map(r => Math.max(0, r.ms - r.server))) }),
        download: async card => {
            const { ms, body } = await timed(card.dataset.download);
            return text(card, 'download', { mbit: (body.byteLength * 8) / (ms / 1000) / 1e6 });
        },
        window: async card => {
            const start = performance.now();
            const run = await timed(card.dataset.testWindow, { headers: { 'X-DocHamster-Window': '1', Accept: 'text/html' } });
            new DOMParser().parseFromString(new TextDecoder().decode(run.body), 'text/html');
            return text(card, 'window', { server: run.server, total: performance.now() - start });
        },
    };

    document.addEventListener('submit', async event => {
        const form = event.target.closest?.('form[data-speed-run]');
        const card = form?.closest('[data-speed-tests]');
        if (!card) {
            return;
        }
        event.preventDefault();
        const button = form.querySelector('button');
        button?.setAttribute('disabled', '');
        for (const row of card.querySelectorAll('[data-speed-test]')) {
            const pill = row.querySelector('[data-speed-result]');
            pill.hidden = false;
            pill.className = 'pill';
            pill.textContent = card.dataset.running;
            try {
                pill.textContent = await tests[row.dataset.speedTest](card);
                pill.classList.add('pill-ok');
            } catch {
                pill.textContent = card.dataset.failed;
                pill.classList.add('pill-error');
            }
        }
        button?.removeAttribute('disabled');
    });
})();
