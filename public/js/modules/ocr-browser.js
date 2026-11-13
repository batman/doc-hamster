/* OCR fallback (point 18) */
// Reads receipt text in the browser with self-hosted tesseract.js when the
// server can't (body[data-ocr-browser]). One image at a time, only while the
// page is visible and idle, not on cellular/data saver/low battery, and not
// while paused in the menu (point 57, localStorage dh.ocr.paused, every tab).
// Point 130/A275: every document is read once at full resolution with a time
// limit of 10 minutes; A276: only when that runs over, one single retry scaled
// down to 2000 px. Point 58/A63: the server counts failures (three → failed).
// A61: an engine that does not start is reported and retried after 5 minutes
// instead of spinning. Point 61: results carry their duration; engine start
// goes to the OCR log.
(() => {
  const startOcr = () => {
    const base = document.body?.dataset.ocrBrowser;
    const nextUrl = document.body?.dataset.ocrNext;
    if (!base || !nextUrl || typeof Worker === 'undefined' || typeof WebAssembly === 'undefined') return;
    // side = long side in px (0 = original), scale = largest PDF page scale. FULL first, RETRY only after a timeout (A276).
    const FULL = { side: 0, scale: 3.5 };
    const RETRY = { side: 2000, scale: 3.5 };
    const LIMIT_MS = 10 * 60000;
    const PAUSE_MS = 5000;
    const MAX_PIXELS = 24e6;
    const WAIT_MS = 60000;
    const ENGINE_MS = 60000;
    const ENGINE_RETRY_MS = 5 * 60000;
    const engineUrl = document.body?.dataset.ocrEngine;
    const logUrl = document.body?.dataset.ocrLog;
    const PAUSE_KEY = 'dh.ocr.paused';
    const skip = [];
    // One lane reads one document at a time (the second lane of A78 read only the retired pass 1).
    const lanes = [{ main: true, engine: null, running: null, active: true }];
    const wakers = new Set();

    const paused = () => {
        try { return localStorage.getItem(PAUSE_KEY) === '1'; } catch { return false; }
    };
    // A sleep that "Fortsetzen" ends early.
    const sleep = ms => new Promise(resolve => {
        const done = () => { clearTimeout(timer); wakers.delete(done); resolve(); };
        const timer = setTimeout(done, ms);
        wakers.add(done);
    });
    const idle = () => new Promise(resolve => ('requestIdleCallback' in window
        ? window.requestIdleCallback(() => resolve(), { timeout: 30000 })
        : setTimeout(resolve, 1000)));
    const allowed = async () => {
        if (document.visibilityState !== 'visible') return false;
        const c = navigator.connection;
        if (c && (c.saveData === true || c.type === 'cellular')) return false;
        if (navigator.getBattery) {
            try {
                const b = await navigator.getBattery();
                if (!b.charging && b.level <= 0.2) return false;
            } catch { /* no battery info */ }
        }
        return true;
    };
    const loadLib = () => (window.Tesseract ? Promise.resolve() : new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = base + '/tesseract.min.js';
        s.onload = resolve;
        s.onerror = reject;
        document.head.append(s);
    }));
    // Rejects after ms; the timer is cleared either way.
    const within = (promise, ms, reason) => {
        let timer = null;
        const limit = new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(reason)), ms); });
        return Promise.race([promise, limit]).finally(() => clearTimeout(timer));
    };
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const post = (url, fields) => {
        if (!url) return;
        const body = new FormData();
        Object.entries(fields).forEach(([key, value]) => body.append(key, String(value)));
        fetch(url, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
            .catch(() => { /* only a hint */ });
    };
    // A61: the engine start has 60 s; a worker that comes up later is closed again.
    const getEngine = async lane => {
        if (lane.engine) return lane.engine;
        let late = false;
        const began = performance.now();
        const create = (async () => {
            await loadLib();
            return window.Tesseract.createWorker('deu+eng', 1, {
                workerPath: base + '/worker.min.js',
                corePath: base + '/core',
                langPath: base + '/lang',
                gzip: false,
                workerBlobURL: false,
                // Only the main lane feeds the progress in the menu.
                logger: lane.main ? m => {
                    document.dispatchEvent(new CustomEvent('dh:ocr-progress', { detail: m }));
                    window.DocHamster?.emit('ocr:progress', m);
                } : () => {},
                errorHandler: () => { /* surfaces as a rejected job */ },
            });
        })();
        create.then(w => { if (late) w.terminate().catch(() => {}); }, () => {});
        try {
            lane.engine = await within(create, ENGINE_MS, 'timeout 60 s');
        } catch (e) {
            late = true;
            throw e;
        }
        // Once per lane and page, so an engine closed after a timeout does not flood the log.
        if (!lane.logged) post(logUrl, { event: 'engine_started', duration: Math.round(performance.now() - began) });
        lane.logged = true;
        return lane.engine;
    };
    const reportEngine = error => post(engineUrl, { reason: String(error?.message || error || 'unknown').slice(0, 300) });
    const stop = async lane => {
        if (!lane.engine) return;
        const e = lane.engine;
        lane.engine = null;
        try { await e.terminate(); } catch { /* already gone */ }
    };
    // Pause (point 57): the running documents are dropped unsaved and the engines closed, so the computer gets its resources back.
    const onPause = isPaused => {
        if (isPaused) {
            lanes.forEach(lane => {
                if (lane.running) lane.running.aborted = true;
                stop(lane);
            });
        } else {
            [...wakers].forEach(wake => wake());
        }
    };
    document.addEventListener('dh:ocr-pause', event => onPause(event.detail === true));
    window.addEventListener('storage', event => { if (event.key === PAUSE_KEY) onPause(event.newValue === '1'); });
    const check = run => { if (run.aborted || run.timedOut) throw new Error('stopped'); };
    // Point 119: word positions relative to the page (0..1, 4 decimals), confidence, line and block index.
    const r4 = v => Math.round(Math.min(1, Math.max(0, Number.isFinite(v) ? v : 0)) * 1e4) / 1e4;
    const word = (t, x, y, w, h, c, l, b) => ({ t, x: r4(x), y: r4(y), w: r4(w), h: r4(h), c: Math.round(Math.min(100, Math.max(0, c ?? 0))), l, b });
    const READ_OUTPUT = { text: true, blocks: true, hocr: false, tsv: false };
    // tesseract.js 5: blocks → paragraphs → lines → words with bbox in px of the image it read.
    const ocrWords = (data, width, height, n) => {
        const words = [];
        if (!data?.blocks || !width || !height) return { n, words };
        let l = 0;
        data.blocks.forEach((block, b) => (block.paragraphs ?? []).forEach(par => (par.lines ?? []).forEach(line => {
            (line.words ?? []).forEach(w => {
                const t = (w.text ?? '').trim();
                if (!t || !w.bbox) return;
                words.push(word(t, w.bbox.x0 / width, w.bbox.y0 / height, (w.bbox.x1 - w.bbox.x0) / width, (w.bbox.y1 - w.bbox.y0) / height, w.confidence, l, b));
            });
            l++;
        })));
        return { n, words };
    };
    // pdf.js text layer: each item's matrix in the scale-1 viewport, split into words by characters.
    const layerWords = (items, viewport, n) => {
        const [a, b, c, d, e, f] = viewport.transform;
        const words = [];
        let l = 0;
        let lineY = null;
        let eol = false;
        items.forEach(item => {
            const str = item.str ?? '';
            const m = item.transform;
            if (m && str.trim()) {
                const tx = [a * m[0] + c * m[1], b * m[0] + d * m[1], a * m[2] + c * m[3], b * m[2] + d * m[3], a * m[4] + c * m[5] + e, b * m[4] + d * m[5] + f];
                const size = Math.hypot(tx[2], tx[3]);
                const base = tx[5];
                if (lineY !== null && (eol || Math.abs(base - lineY) > size * 0.5)) l++;
                lineY = base;
                eol = false;
                const width = (item.width ?? 0) * viewport.scale;
                const re = /\S+/g;
                let hit;
                while ((hit = re.exec(str))) {
                    words.push(word(hit[0], (tx[4] + width * hit.index / str.length) / viewport.width, (base - size) / viewport.height,
                        width * hit[0].length / str.length / viewport.width, size / viewport.height, 100, l, 0));
                }
            }
            if (item.hasEOL) eol = true;
        });
        return { n, words };
    };
    // Point 124: the Live card follows the main lane page by page (DocHamster events; only this tab sees them).
    const pageStart = (lane, info, n) => { if (lane.main) window.DocHamster?.emit('ocr:page-start', { id: info.id, n, pages: info.pages ?? 1 }); };
    const pageDone = (lane, info, page) => { if (lane.main) window.DocHamster?.emit('ocr:page', { id: info.id, n: page.n, pages: info.pages ?? 1, words: page.words }); };
    // Images: original size (A276 retry: 2000 px long side), capped for memory.
    const readImage = async (lane, url, side, run, info) => {
        pageStart(lane, info, 1);
        const read = async (image, width, height) => {
            const { data } = await (await getEngine(lane)).recognize(image, {}, READ_OUTPUT);
            const page = ocrWords(data, width, height, 1);
            info.words = { source: 'browser', pages: [page] };
            pageDone(lane, info, page);
            return data?.text ?? '';
        };
        if (typeof createImageBitmap === 'undefined') return (await (await getEngine(lane)).recognize(url)).data?.text ?? '';
        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('image');
        const blob = await res.blob();
        const bitmap = await createImageBitmap(blob);
        try {
            check(run);
            const factor = Math.min(side > 0 ? side / Math.max(bitmap.width, bitmap.height) : 1, Math.sqrt(MAX_PIXELS / (bitmap.width * bitmap.height)));
            if (factor >= 1) return await read(blob, bitmap.width, bitmap.height);
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(bitmap.width * factor));
            canvas.height = Math.max(1, Math.round(bitmap.height * factor));
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            try {
                return await read(canvas, canvas.width, canvas.height);
            } finally {
                canvas.width = canvas.height = 0;
            }
        } finally {
            bitmap.close?.();
        }
    };
    // Point 34: PDFs page by page with pdf.js (document viewer block). A page that already carries text
    // gives that text; a scanned page is drawn at full scale (A276 retry: 2000 px long side) and read by tesseract.
    // Pages joined by a blank line.
    // Point 69: gives the page count and how many pages came from the text layer (A106/A114) for the OCR log.
    const readPdf = async (lane, url, size, run, info) => {
        if (!DocHamster.openPdf) throw new Error('pdf.js not available');
        const doc = await DocHamster.openPdf(url);
        try {
            const texts = [];
            const pages = [];
            const last = Math.min(doc.numPages, 50);
            info.pages = doc.numPages;
            info.layer = 0;
            for (let n = 1; n <= last; n++) {
                check(run);
                pageStart(lane, info, n);
                const page = await doc.getPage(n);
                const layer = await page.getTextContent();
                const own = layer.items.map(item => (item.str ?? '') + (item.hasEOL ? '\n' : ' ')).join('').trim();
                if (own.replace(/\s/g, '').length >= 20) {
                    texts.push(own);
                    pages.push(layerWords(layer.items, page.getViewport({ scale: 1 }), n));
                    pageDone(lane, info, pages[pages.length - 1]);
                    info.layer++;
                } else {
                    const one = page.getViewport({ scale: 1 });
                    const scale = Math.min(size.scale, size.side > 0 ? size.side / Math.max(one.width, one.height) : Infinity, Math.sqrt(MAX_PIXELS / (one.width * one.height)));
                    const viewport = page.getViewport({ scale });
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.floor(viewport.width);
                    canvas.height = Math.floor(viewport.height);
                    await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport }).promise;
                    const { data } = await (await getEngine(lane)).recognize(canvas, {}, READ_OUTPUT);
                    texts.push((data?.text ?? '').trim());
                    pages.push(ocrWords(data, canvas.width, canvas.height, n));
                    pageDone(lane, info, pages[pages.length - 1]);
                    canvas.width = canvas.height = 0;
                }
                page.cleanup();
            }
            info.words = { source: pages.length > 0 && info.layer === pages.length ? 'layer' : 'browser', pages };
            return texts.filter(Boolean).join('\n\n');
        } finally {
            Promise.resolve(doc.loadingTask?.destroy ? doc.loadingTask.destroy() : doc.destroy?.()).catch(() => {}); /* pdf.js 6: freed via its loading task */
        }
    };
    const announce = (lane, job) => {
        if (!lane.main) return;
        document.dispatchEvent(new CustomEvent('dh:ocr-job', { detail: job }));
        window.DocHamster?.emit('ocr:job', job);
    };

    // One open document (A77: the server picks at random among them); gives the pause before the next one, or null
    // when nothing is left.
    const step = async lane => {
        const url = new URL(nextUrl, location.href);
        skip.forEach(id => url.searchParams.append('skip[]', id));
        const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (res.status !== 200) return null;
        const job = await res.json();
        // A61: no engine, no document – the reason goes to the server, the next try comes in 5 minutes.
        try {
            await getEngine(lane);
        } catch (e) {
            reportEngine(e);
            await stop(lane);
            return lane.main ? ENGINE_RETRY_MS : null;
        }
        let run = { aborted: false, timedOut: false };
        lane.running = run;
        announce(lane, job);
        const body = new FormData();
        const began = performance.now();
        const info = { id: job.id, pages: null, layer: null, words: null };
        const attempt = size => {
            const read = job.type === 'application/pdf'
                ? readPdf(lane, job.file, size, run, info)
                : readImage(lane, job.file, size.side, run, info);
            read.catch(() => {});
            return within(read, LIMIT_MS, 'timeout');
        };
        try {
            let text;
            try {
                text = await attempt(FULL);
            } catch (e) {
                if (e?.message !== 'timeout' || run.aborted) throw e;
                // A276: over the time limit at full resolution – the engine is closed and the document read once more, smaller.
                run.timedOut = true;
                await stop(lane);
                run = { aborted: false, timedOut: false };
                lane.running = run;
                text = await attempt(RETRY);
            }
            body.append('text', text);
            // Point 119: the positions go along when they fit the server's limit (2 MB).
            const words = info.words?.pages?.some(p => p.words.length) ? JSON.stringify(info.words) : '';
            if (words && words.length < 2e6) body.append('words', words);
        } catch (e) {
            // Point 58: over the time limit the engine is closed, so the computer gets its resources back.
            if (e?.message === 'timeout') {
                run.timedOut = true;
                await stop(lane);
            }
            body.append('failed', '1');
            body.append('reason', String(e?.message || e || 'unknown').slice(0, 290));
        }
        body.append('duration', String(Math.round(performance.now() - began)));
        if (info.pages !== null) body.append('pages', String(info.pages));
        if (info.layer !== null) body.append('layer', String(info.layer));
        lane.running = null;
        if (run.aborted) {
            // Paused: nothing saved, the document comes again after "Fortsetzen".
            announce(lane, null);
            return 0;
        }
        try {
            const saved = await fetch(job.save, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' });
            if (!saved.ok) skip.push(job.id);
        } catch {
            skip.push(job.id);
        }
        announce(lane, null);
        return PAUSE_MS;
    };

    // The lane runs until nothing is left.
    const runLane = async lane => {
        lane.active = true;
        try {
            if (lane.main) await sleep(5000);
            for (;;) {
                if (paused()) {
                    await stop(lane);
                    await sleep(WAIT_MS);
                    continue;
                }
                await idle();
                if (paused()) continue;
                if (!(await allowed())) {
                    await stop(lane);
                    await sleep(WAIT_MS);
                    continue;
                }
                let pause = null;
                try { pause = await step(lane); } catch { pause = null; }
                if (pause === null) { await stop(lane); return; }
                if (pause > 0) await sleep(pause);
            }
        } finally {
            lane.active = false;
        }
    };
    runLane(lanes[0]);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startOcr);
  else startOcr();
})();
