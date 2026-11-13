// Live card on the Texterkennung page (point 124, A255-A259). Shows the document being read with the recognised words
// as orange boxes (.doc-mark, unsure words .is-faint), page x of y, engine, percent of the page, elapsed time and
// the tesseract.js step; figures and the last ten finished documents. Two sources:
//  - the browser OCR of THIS tab (modules/ocr-browser.js) reports through DocHamster events: ocr:job, ocr:page-start,
//    ocr:page (with the page's words), ocr:progress – truly live;
//  - otherwise the status JSON (?live=1) that modules/ocr-progress.js polls every 3 s (ocr:status) – server reads and
//    browser reads in another tab; words appear when the document is done (documents.words).
// Idle: "Wartet auf Dokumente" and the last finished document with its words. Every card keeps its own state, so the
// page can be open in several windows.
(() => {
    const cards = new Set();
    const state = new WeakMap();
    // This tab's browser OCR (shared by all cards of the tab).
    const local = { job: null, n: null, pages: null, percent: null, step: null, started: 0, words: {} };

    const fmt = (text, values) => Object.entries(values).reduce((t, [k, v]) => t.replaceAll(`:${k}`, String(v)), text ?? '');
    const duration = (labels, s) => {
        if (s === null || s === undefined) return labels.none;
        if (s < 60) return fmt(labels.seconds, { s: Math.max(0, Math.round(s)) });
        if (s < 3600) return fmt(labels.minutes, { m: Math.ceil(s / 60) });
        return fmt(labels.hours, { h: Math.floor(s / 3600), m: Math.floor((s % 3600) / 60) });
    };
    const decimal = v => (v === null || v === undefined ? null : Number(v).toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 }));

    // --- preview: one page with its word boxes -------------------------------------------------------------------
    const pdfOf = async (st, url) => {
        if (st.pdf?.url === url) return st.pdf.doc;
        if (st.pdf) Promise.resolve(st.pdf.doc?.loadingTask?.destroy?.()).catch(() => {});
        st.pdf = { url, doc: null };
        const doc = await DocHamster.openPdf(url);
        if (st.pdf?.url !== url) {
            Promise.resolve(doc.loadingTask?.destroy?.()).catch(() => {});
            throw new Error('stale');
        }
        st.pdf.doc = doc;
        return doc;
    };
    const drawMedia = async (card, st, view) => {
        const box = card.querySelector('[data-ocr-live-page]');
        const key = `${view.file ?? view.thumb}#${view.n}`;
        if (st.mediaKey === key) return true;
        st.mediaKey = key;
        const gen = ++st.gen;
        let media = null;
        try {
            if (view.file && view.type === 'application/pdf' && DocHamster.openPdf) {
                const doc = await pdfOf(st, view.file);
                const page = await doc.getPage(Math.min(Math.max(1, view.n), doc.numPages));
                const one = page.getViewport({ scale: 1 });
                const viewport = page.getViewport({ scale: Math.min(2, 520 / one.width) });
                const canvas = document.createElement('canvas');
                canvas.width = Math.floor(viewport.width);
                canvas.height = Math.floor(viewport.height);
                await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport }).promise;
                media = canvas;
            } else if (view.file || view.thumb) {
                media = await new Promise(resolve => {
                    const img = new Image();
                    img.alt = '';
                    img.decoding = 'async';
                    img.onload = () => resolve(img);
                    img.onerror = () => {
                        if (view.thumb && img.src !== new URL(view.thumb, location.href).href) img.src = view.thumb;
                        else resolve(null);
                    };
                    img.src = view.file && view.type?.startsWith('image/') ? view.file : view.thumb;
                });
            }
        } catch {
            media = null;
        }
        if (gen !== st.gen) return false;
        box.replaceChildren(...(media ? [media] : []));
        box.hidden = !media;
        if (!media) st.mediaKey = null;
        return !!media;
    };
    const drawWords = (card, words) => {
        const box = card.querySelector('[data-ocr-live-page]');
        box.querySelectorAll('.doc-mark').forEach(mark => mark.remove());
        (words ?? []).forEach(w => {
            const mark = document.createElement('div');
            mark.className = (w.c ?? 100) < 60 ? 'doc-mark is-faint' : 'doc-mark';
            mark.style.left = `${w.x * 100}%`;
            mark.style.top = `${w.y * 100}%`;
            mark.style.width = `${w.w * 100}%`;
            mark.style.height = `${w.h * 100}%`;
            box.append(mark);
        });
    };
    const NONE = [];
    const show = async (card, st, view) => {
        if (view && st.mediaKey === `${view.file ?? view.thumb}#${view.n}` && st.wordsRef === view.words) return;
        if (!view) {
            st.mediaKey = null;
            st.wordsRef = null;
            const box = card.querySelector('[data-ocr-live-page]');
            box.replaceChildren();
            box.hidden = true;
            return;
        }
        if (await drawMedia(card, st, view)) {
            st.wordsRef = view.words;
            drawWords(card, view.words);
        }
    };

    // Words of a finished document (idle and server mode), fetched once per document.
    const wordsOf = async (st, row) => {
        if (st.words.has(row.id)) return st.words.get(row.id);
        st.words.set(row.id, null);
        try {
            const res = await fetch(row.words, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = res.ok ? await res.json() : null;
            st.words.set(row.id, data?.pages ?? []);
        } catch {
            st.words.set(row.id, []);
        }
        return st.words.get(row.id);
    };

    // --- details, figures, recent ---------------------------------------------------------------------------------
    const set = (card, name, text) => {
        const el = card.querySelector(`[data-live="${name}"]`);
        if (el && el.textContent !== text) el.textContent = text;
    };
    const details = (card, st, d) => {
        const L = st.labels;
        set(card, 'title', d?.title ?? L.none);
        set(card, 'page', d?.page ? (d.pages ? fmt(L.page_of, { page: d.page, pages: d.pages }) : String(d.page)) : L.none);
        set(card, 'engine', d?.engine ? (L.engines?.[d.engine] ?? d.engine) : L.none);
        set(card, 'percent', d?.percent !== null && d?.percent !== undefined ? `${d.percent} %` : L.none);
        st.started = d?.started ?? 0;
        set(card, 'elapsed', st.started ? duration(L, (Date.now() / 1000) - st.started) : L.none);
        card.querySelectorAll('[data-live-step]').forEach(el => { el.hidden = !d?.step; });
        set(card, 'step', d?.step ?? '');
        card.querySelector('[data-ocr-live-idle]').hidden = !!d?.live;
    };
    const figures = (card, st, f) => {
        if (!f) return;
        const L = st.labels;
        const put = (name, text) => {
            const el = card.querySelector(`[data-live-figure="${name}"]`);
            if (el) el.textContent = text;
        };
        put('per_hour', String(f.per_hour ?? 0));
        put('remaining_seconds', duration(L, f.remaining_seconds));
        put('seconds_per_page', decimal(f.seconds_per_page) ?? L.none);
    };
    const icon = (name, el) => {
        // Reuse the symbols the server drew (check / x / file-text), so no SVG lives in this file.
        const source = el.closest('[data-ocr-live]')?.querySelector(`[data-live-icon="${name}"]`);
        return source?.firstElementChild ? source.firstElementChild.cloneNode(true) : document.createElement('span');
    };
    const recent = (card, st, rows) => {
        if (!Array.isArray(rows)) return;
        const sig = JSON.stringify(rows.map(r => [r.id, r.ok, r.value, r.thumb]));
        if (sig === st.recentSig) return;
        st.recentSig = sig;
        const list = card.querySelector('[data-ocr-live-recent]');
        if (!rows.length) {
            const empty = document.createElement('li');
            empty.className = 'list-empty';
            empty.textContent = st.labels.recent_empty;
            list.replaceChildren(empty);
            return;
        }
        list.replaceChildren(...rows.map(row => {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.className = 'ocr-live-doc';
            a.href = row.url;
            a.title = row.title;
            a.setAttribute('data-window', '');
            const frame = document.createElement('span');
            frame.className = 'thumb-frame';
            if (row.thumb) {
                const img = document.createElement('img');
                img.className = 'thumb';
                img.src = row.thumb;
                img.alt = '';
                img.loading = 'lazy';
                img.draggable = false;
                frame.append(img);
            } else {
                frame.append(icon('file', list));
            }
            const result = document.createElement('span');
            result.className = `ocr-live-result ${row.ok ? 'is-ok' : 'is-failed'}`;
            result.setAttribute('role', 'img');
            result.setAttribute('aria-label', row.ok ? st.labels.ok : st.labels.failed);
            result.append(icon(row.ok ? 'ok' : 'failed', list));
            const text = document.createElement('span');
            text.className = 'ocr-live-doc-text';
            text.textContent = row.value ?? row.title;
            a.append(frame, result, text);
            li.append(a);
            return li;
        }));
    };

    // --- the two sources ------------------------------------------------------------------------------------------
    const stepText = (labels, m) => {
        if (!m) return null;
        if (m.status === 'recognizing text') return fmt(labels.steps?.['recognizing text'], { percent: Math.round((m.progress ?? 0) * 100) });
        return labels.steps?.[m.status] ?? labels.steps?.searching ?? null;
    };
    const paintLocal = card => {
        const st = state.get(card);
        if (!st || !local.job) return;
        details(card, st, {
            live: true,
            title: local.job.title ?? st.serverTitle?.[local.job.id] ?? '…',
            page: local.n,
            pages: local.pages,
            engine: 'browser',
            percent: local.percent,
            started: local.started,
            step: local.step,
        });
        show(card, st, { file: local.job.file, type: local.job.type, n: local.n ?? 1, words: local.words[local.n ?? 1] ?? NONE });
    };
    const paintServer = (card, data) => {
        const st = state.get(card);
        if (!st) return;
        st.last = data;
        figures(card, st, data.figures);
        recent(card, st, data.recent);
        if (local.job) {
            // This tab reads: the server only tells the document's title.
            if (data.current?.id === local.job.id) (st.serverTitle ??= {})[local.job.id] = data.current.title;
            paintLocal(card);
            return;
        }
        const c = data.current;
        if (c) {
            details(card, st, { ...c, live: true, engine: c.engine === 'browser' ? 'browser_other' : 'server' });
            show(card, st, { file: c.file, type: c.type, thumb: c.thumb, n: c.page ?? 1, words: NONE });
            return;
        }
        // Idle: the last finished document with its words.
        const row = (data.recent ?? [])[0];
        details(card, st, row ? { title: fmt(st.labels.last, { title: row.title }) } : null);
        if (!row) {
            show(card, st, null);
            return;
        }
        const view = { file: row.file, type: row.mime, thumb: row.thumb, n: 1, words: NONE };
        show(card, st, view);
        wordsOf(st, row).then(pages => {
            if (st.last !== data || !pages) return;
            show(card, st, { ...view, words: pages.find(p => p.n === 1)?.words ?? NONE });
        });
    };

    const each = fn => cards.forEach(card => { if (card.isConnected) fn(card); else cards.delete(card); });
    const setUp = card => {
        if (state.has(card)) return;
        let labels = {};
        let first = null;
        try { labels = JSON.parse(card.dataset.labels ?? '{}'); } catch { /* defaults stay empty */ }
        try { first = JSON.parse(card.dataset.ocrLive ?? 'null'); } catch { /* the poll fills it */ }
        const st = { labels, gen: 0, mediaKey: null, pdf: null, words: new Map(), started: 0, last: null, recentSig: null };
        state.set(card, st);
        cards.add(card);
        if (first) paintServer(card, first);
        if (local.job) paintLocal(card);
        // Elapsed time ticks every second while something is read.
        const timer = setInterval(() => {
            if (!card.isConnected) {
                clearInterval(timer);
                cards.delete(card);
                return;
            }
            if (st.started) set(card, 'elapsed', duration(st.labels, (Date.now() / 1000) - st.started));
        }, 1000);
    };

    DocHamster.on('ocr:status', ({ page, data }) => page.querySelectorAll('[data-ocr-live]').forEach(card => paintServer(card, data)));
    DocHamster.on('ocr:job', job => {
        local.job = job;
        local.n = null;
        local.pages = null;
        local.percent = null;
        local.step = null;
        local.words = {};
        local.started = Date.now() / 1000;
        if (job) each(paintLocal);
        else each(card => { const st = state.get(card); if (st?.last) paintServer(card, st.last); });
    });
    DocHamster.on('ocr:page-start', d => {
        if (!local.job || d.id !== local.job.id) return;
        local.n = d.n;
        local.pages = d.pages;
        local.percent = 0;
        local.step = null;
        each(card => { const st = state.get(card); local.step = stepText(st.labels, { status: 'searching' }); paintLocal(card); });
    });
    DocHamster.on('ocr:page', d => {
        if (!local.job || d.id !== local.job.id) return;
        local.words[d.n] = d.words ?? [];
        local.percent = 100;
        each(paintLocal);
    });
    let lastPaint = 0;
    DocHamster.on('ocr:progress', m => {
        if (!local.job) return;
        if (m?.status === 'recognizing text' && typeof m.progress === 'number') local.percent = Math.round(m.progress * 100);
        const now = Date.now();
        if (now - lastPaint < 250 && m?.progress !== 1) return;
        lastPaint = now;
        each(card => { const st = state.get(card); local.step = stepText(st.labels, m); paintLocal(card); });
    });

    DocHamster.on('window:open', root => root.querySelectorAll?.('[data-ocr-live]').forEach(setUp));
    const start = () => document.querySelectorAll('[data-ocr-live]').forEach(setUp);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
