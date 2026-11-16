// PDFs in the document viewer (frame[data-pdf], point 33/A19; split from modules/document-viewer.js): pdf.js draws the
// page into a canvas.thumb that takes the image's place; it is drawn again at device pixels x zoom after each zoom, so
// the text stays sharp. Point 240: up to 1000 % – the canvas never grows past PDF_MAX_PX device pixels (nor a side past
// PDF_MAX_SIDE); beyond that the page is drawn at the largest scale that fits and only CSS scales it further, so a
// deep zoom never crashes or leaves the canvas blank. Viewer API parts: goTo, init, release, page.
(() => {
    const DocHamster = window.DocHamster;
    const core = DocHamster.viewer._core;
    const { pdfs, states, stateOf, apply, clearBox } = core;

    // pdf.js (self-hosted in public/vendor/pdfjs, body[data-pdfjs]); loaded once, one worker for all documents.
    let pdfLib = null;
    const loadPdf = () => (pdfLib ??= (async () => {
        const base = document.body?.dataset.pdfjs;
        if (!base) throw new Error('pdf.js not available');
        const lib = await import(base + '/pdf.min.js');
        lib.GlobalWorkerOptions.workerSrc = base + '/pdf.worker.min.js';
        let worker = null;
        try { worker = new lib.PDFWorker({ name: 'dochamster' }); } catch { /* pdf.js makes its own */ }
        return { lib, base, worker };
    })());
    // Opens a PDF (also used by the browser text recognition). The file comes with the session cookie.
    const openPdf = async url => {
        const { lib, base, worker } = await loadPdf();
        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = new Uint8Array(await res.arrayBuffer());
        return lib.getDocument({ data, worker, enableXfa: false, wasmUrl: base + '/wasm/' }).promise;
    };
    DocHamster.openPdf = openPdf;

    const PDF_MAX_PX = 16e6; // canvas limit of mobile Safari
    const PDF_MAX_SIDE = 16384; // longest canvas side most browsers draw
    const live = new Set();
    const free = doc => Promise.resolve(doc?.loadingTask?.destroy ? doc.loadingTask.destroy() : doc?.destroy?.()).catch(() => {});
    const drop = frame => {
        const pdf = pdfs.get(frame);
        if (!pdf) return;
        pdfs.delete(frame);
        live.delete(frame);
        clearTimeout(pdf.timer);
        pdf.task?.cancel();
        resized?.unobserve(frame);
        // pdf.js 6 frees a document through its loading task (PDFDocumentProxy has no destroy() any more); an error
        // here once made every window open fall back to a full page load (point 114).
        free(pdf.doc);
    };
    const sweep = () => live.forEach(frame => { if (!frame.isConnected) drop(frame); });
    // The zoom level a drawing is made for: the zoom, but never above the level where the canvas reaches its cap.
    const wanted = (pdf, s) => Math.min(Math.max(1, s), pdf.cap ?? Infinity);
    const draw = async (frame, pdf) => {
        const gen = ++pdf.gen;
        const st = states.get(frame);
        let level = wanted(pdf, st?.s ?? 1);
        let page;
        try { page = await pdf.doc.getPage(pdf.page); } catch { return; }
        if (gen !== pdf.gen || !frame.isConnected) return;
        const one = page.getViewport({ scale: 1 });
        const cs = getComputedStyle(frame);
        const w = frame.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        const h = frame.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
        if (w <= 0 || h <= 0) return; // hidden window: drawn when it gets a size
        const fit = Math.min(w / one.width, h / one.height) * (window.devicePixelRatio || 1);
        // The largest scale the canvas allows (area and side); its zoom level is remembered as the cap.
        const most = Math.min(Math.sqrt(PDF_MAX_PX / (one.width * one.height)), PDF_MAX_SIDE / Math.max(one.width, one.height));
        pdf.cap = Math.max(1, most / fit);
        level = Math.min(level, pdf.cap);
        const viewport = page.getViewport({ scale: Math.min(most, fit * level) });
        const canvas = document.createElement('canvas');
        canvas.className = 'thumb doc-canvas';
        canvas.width = Math.max(1, Math.floor(viewport.width));
        canvas.height = Math.max(1, Math.floor(viewport.height));
        pdf.task?.cancel();
        const task = pdf.task = page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport });
        try { await task.promise; } catch { return; } // cancelled by a newer drawing, or failed: the old picture stays
        if (gen !== pdf.gen || !frame.isConnected) return;
        pdf.task = null;
        pdf.drawn = level;
        pdf.width = one.width; // PDF points, for "100 %" (point 168.2)
        const old = frame.querySelector(':scope > .thumb, :scope > .icon');
        if (old) old.replaceWith(canvas); else frame.prepend(canvas);
        const now = stateOf(frame);
        now.img = canvas;
        apply(frame, now);
        DocHamster.emit('viewer:page', { frame, page: pdf.page });
        (pdf.waiters ?? []).splice(0).forEach(done => done(true));
    };
    const redraw = (frame, pdf, wait) => {
        clearTimeout(pdf.timer);
        pdf.timer = setTimeout(() => draw(frame, pdf), wait);
    };
    // After every zoom (document-viewer.js apply): a sharper drawing once the zoom has settled.
    core.zoomed = (frame, st) => {
        const pdf = pdfs.get(frame);
        if (pdf && pdf.drawn !== wanted(pdf, st.s)) redraw(frame, pdf, 150);
    };
    const resized = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(entries => entries.forEach(entry => {
        const pdf = pdfs.get(entry.target);
        if (pdf) { pdf.drawn = null; pdf.cap = null; redraw(entry.target, pdf, 150); }
    }));
    // A19: "Seite 1 von 3" with previous/next, only for more than one page.
    const showPages = (frame, pdf) => {
        const box = frame.nextElementSibling?.querySelector('[data-doc-pages]');
        if (!box) return;
        box.hidden = pdf.pages < 2;
        box.querySelector('[data-page-label]').textContent = box.dataset.label.replace(':pages', pdf.pages).replace(':page', pdf.page);
        box.querySelector('[data-page-step="-1"]').disabled = pdf.page <= 1;
        box.querySelector('[data-page-step="1"]').disabled = pdf.page >= pdf.pages;
    };
    const show = (frame, pdf, to) => {
        pdf.page = to;
        const st = states.get(frame);
        if (st) { clearBox(st); st.s = 1; st.x = 0; st.y = 0; }
        pdf.drawn = null;
        pdf.cap = null;
        showPages(frame, pdf);
        redraw(frame, pdf, 0);
    };
    const turn = (frame, step) => {
        const pdf = pdfs.get(frame);
        const to = pdf ? Math.min(pdf.pages, Math.max(1, pdf.page + step)) : 0;
        if (!pdf || to === pdf.page) return;
        show(frame, pdf, to);
    };
    const initPdf = async frame => {
        if (frame.dataset.pdfReady !== undefined) return;
        frame.dataset.pdfReady = '';
        sweep();
        let doc;
        try { doc = await openPdf(frame.dataset.pdf); } catch { return; } // the preview image stays
        if (!frame.isConnected) { free(doc); return; }
        const pdf = { doc, page: 1, pages: doc.numPages, gen: 0, drawn: null, cap: null, timer: 0, task: null };
        pdfs.set(frame, pdf);
        live.add(frame);
        showPages(frame, pdf);
        if (resized) resized.observe(frame); else redraw(frame, pdf, 0);
    };
    DocHamster.on('window:open', root => root.querySelectorAll?.('.detail-doc[data-pdf]').forEach(initPdf));
    DocHamster.on('window:close', root => root.querySelectorAll?.('.detail-doc[data-pdf]').forEach(drop));

    Object.assign(DocHamster.viewer, {
        page: frame => pdfs.get(frame)?.page ?? 1,
        // Shows that page; resolves true when it is drawn (false when the document has no such page).
        goTo: (frame, page) => {
            const pdf = pdfs.get(frame);
            if (!pdf) return Promise.resolve(page === 1);
            if (page < 1 || page > pdf.pages) return Promise.resolve(false);
            if (page === pdf.page && pdf.drawn !== null) return Promise.resolve(true);
            return new Promise(done => {
                (pdf.waiters ??= []).push(done);
                if (page !== pdf.page) show(frame, pdf, page);
            });
        },
        // Point 168.2 (fullscreen viewer): pdf.js for a frame added later, and freeing it again.
        init: frame => (frame?.dataset.pdf ? initPdf(frame) : undefined),
        release: drop,
    });

    document.addEventListener('click', e => {
        const btn = e.target.closest?.('[data-page-step]');
        const frame = btn?.closest('[data-doc-zoom]')?.previousElementSibling;
        if (frame) turn(frame, Number(btn.dataset.pageStep));
    });
    document.addEventListener('keydown', e => {
        if (e.key !== 'PageUp' && e.key !== 'PageDown') return;
        if (e.target.matches?.('input, textarea, select, [contenteditable]')) return;
        const frame = e.target.closest?.('.detail-doc[data-pdf]') ?? e.target.closest?.('[data-doc-zoom]')?.previousElementSibling;
        if (!frame || !pdfs.has(frame)) return;
        e.preventDefault();
        turn(frame, e.key === 'PageDown' ? 1 : -1);
    });
})();
