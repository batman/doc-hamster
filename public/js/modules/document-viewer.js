// Document viewer in the detail window: wheel zoom towards the mouse, drag to pan, slider 25-400 %,
// box selection with "Zoomen" / "Kopieren", double click back to fit. Delegated, so windows loaded later work too.
// PDFs (frame[data-pdf], point 33/A19): pdf.js draws the page into a canvas.thumb that takes the image's place;
// it is drawn again at device pixels x zoom after each zoom, so the text stays sharp. All state lives per frame.
(() => {
    const MIN = 0.25, MAX = 4;
    const states = new WeakMap();
    const pdfs = new WeakMap();
    const frameOf = el => {
        const frame = el?.closest?.('.detail-doc');
        return frame && frame.querySelector(':scope > .thumb') && frame.nextElementSibling?.matches('[data-doc-zoom]') ? frame : null;
    };
    const stateOf = frame => {
        let st = states.get(frame);
        if (!st) {
            st = { s: 1, x: 0, y: 0, img: frame.querySelector(':scope > .thumb'), bar: frame.nextElementSibling, box: null };
            states.set(frame, st);
        }
        return st;
    };
    // Point relative to the frame's padding box (where the box overlay and offsetLeft live).
    const point = (frame, e) => {
        const r = frame.getBoundingClientRect();
        return { x: e.clientX - r.left - frame.clientLeft, y: e.clientY - r.top - frame.clientTop };
    };
    // Point 17: the document never leaves the view – its edge stops at the frame's edge; smaller than the frame, it stays centred.
    const clamp = (frame, st) => {
        const fit = (pos, size, box, off) => size <= box ? (box - size) / 2 - off : Math.min(-off, Math.max(box - size - off, pos));
        st.x = fit(st.x, st.img.offsetWidth * st.s, frame.clientWidth, st.img.offsetLeft);
        st.y = fit(st.y, st.img.offsetHeight * st.s, frame.clientHeight, st.img.offsetTop);
    };
    const apply = (frame, st) => {
        clamp(frame, st);
        st.img.style.transformOrigin = '0 0';
        st.img.style.transform = st.s === 1 && !st.x && !st.y ? '' : `translate(${st.x}px, ${st.y}px) scale(${st.s})`;
        frame.classList.toggle('is-zoomed', st.s > 1);
        const pct = Math.round(st.s * 100);
        st.bar.querySelector('[data-zoom-range]').value = pct;
        st.bar.querySelector('[data-zoom-value]').textContent = pct + ' %';
        syncLayer(frame, st);
        const pdf = pdfs.get(frame);
        if (pdf && pdf.drawn !== Math.max(1, st.s)) redraw(frame, pdf, 150);
    };
    // The shown page inside the picture box (object-fit: contain), in unscaled picture pixels.
    const content = st => {
        const img = st.img, w = img.clientWidth, h = img.clientHeight;
        const nw = img.naturalWidth || img.width, nh = img.naturalHeight || img.height;
        if (!w || !h || !nw || !nh) return null;
        const c = Math.min(w / nw, h / nh);
        return { w, h, x: (w - nw * c) / 2, y: (h - nh * c) / 2, pw: nw * c, ph: nh * c };
    };
    // Points 121.2/A240: an overlay over the shown page (.doc-layer > .doc-layer-page) that zooms and pans with it;
    // modules put marks into it in page-relative percent (left: x*100% …). Created on first use.
    const syncLayer = (frame, st) => {
        const layer = st.layer;
        const c = layer && content(st);
        if (!c) return;
        Object.assign(layer.style, {
            left: st.img.offsetLeft + 'px', top: st.img.offsetTop + 'px', width: c.w + 'px', height: c.h + 'px',
            transform: st.img.style.transform,
        });
        layer.style.setProperty('--doc-scale', String(st.s));
        Object.assign(layer.firstElementChild.style, { left: c.x + 'px', top: c.y + 'px', width: c.pw + 'px', height: c.ph + 'px' });
    };
    const layerOf = frame => {
        const st = stateOf(frame);
        if (!st.layer?.isConnected) {
            st.layer = document.createElement('div');
            st.layer.className = 'doc-layer';
            st.layer.setAttribute('aria-hidden', 'true');
            st.layer.append(document.createElement('div'));
            st.layer.firstElementChild.className = 'doc-layer-page';
            frame.append(st.layer);
        }
        syncLayer(frame, st);
        return st.layer.firstElementChild;
    };
    // Zoom to scale s keeping the frame point p fixed.
    const zoomAt = (frame, st, s, p) => {
        s = Math.min(MAX, Math.max(MIN, s));
        const ox = st.img.offsetLeft, oy = st.img.offsetTop;
        const qx = (p.x - ox - st.x) / st.s, qy = (p.y - oy - st.y) / st.s;
        st.x = p.x - ox - s * qx;
        st.y = p.y - oy - s * qy;
        st.s = s;
        apply(frame, st);
    };
    const center = frame => ({ x: frame.clientWidth / 2, y: frame.clientHeight / 2 });
    // Frame point → page-relative point (0..1); null outside the page unless clamped.
    const toPage = (frame, fx, fy, clampIt = false) => {
        const st = stateOf(frame);
        const c = content(st);
        if (!c) return null;
        let x = ((fx - st.img.offsetLeft - st.x) / st.s - c.x) / c.pw;
        let y = ((fy - st.img.offsetTop - st.y) / st.s - c.y) / c.ph;
        if (clampIt) { x = Math.min(1, Math.max(0, x)); y = Math.min(1, Math.max(0, y)); }
        return x < 0 || y < 0 || x > 1 || y > 1 ? null : { x, y };
    };
    const pageOf = frame => pdfs.get(frame)?.page ?? 1;
    const reset = (frame, st) => { st.s = 1; st.x = 0; st.y = 0; apply(frame, st); };
    const clearBox = st => { st.box?.remove(); st.box = null; };
    const clearAll = () => document.querySelectorAll('.doc-box').forEach(box => {
        const st = states.get(box.parentElement);
        if (st) clearBox(st); else box.remove();
    });
    const toast = text => {
        const el = document.createElement('div');
        el.className = 'toast';
        el.setAttribute('role', 'status');
        el.textContent = text;
        document.body.append(el);
        const leave = () => { el.classList.add('is-leaving'); setTimeout(() => el.remove(), 300); };
        el.addEventListener('click', leave);
        setTimeout(leave, 3000);
    };
    // Box (frame coords) to natural pixels of the shown picture (object-fit: contain).
    const crop = (st, b) => {
        const img = st.img, w = img.clientWidth, h = img.clientHeight;
        const nw = img.naturalWidth || img.width, nh = img.naturalHeight || img.height;
        const c = Math.min(w / nw, h / nh), cx = (w - nw * c) / 2, cy = (h - nh * c) / 2;
        const toNat = (px, py) => ({
            x: Math.min(nw, Math.max(0, ((px - img.offsetLeft - st.x) / st.s - cx) / c)),
            y: Math.min(nh, Math.max(0, ((py - img.offsetTop - st.y) / st.s - cy) / c)),
        });
        const a = toNat(b.x, b.y), z = toNat(b.x + b.w, b.y + b.h);
        return { x: a.x, y: a.y, w: z.x - a.x, h: z.y - a.y };
    };
    const copy = (st, b) => {
        const r = crop(st, b);
        if (r.w < 1 || r.h < 1 || !navigator.clipboard?.write || typeof ClipboardItem === 'undefined') return;
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(r.w);
        canvas.height = Math.round(r.h);
        canvas.getContext('2d').drawImage(st.img, r.x, r.y, r.w, r.h, 0, 0, canvas.width, canvas.height);
        const blob = new Promise(done => canvas.toBlob(done, 'image/png'));
        navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })])
            .then(() => toast(st.bar.dataset.labelCopied))
            .catch(() => {});
    };
    const zoomBox = (frame, st, b) => {
        const W = frame.clientWidth, H = frame.clientHeight;
        const s = Math.min(MAX, Math.max(MIN, st.s * Math.min(W / b.w, H / b.h)));
        const ox = st.img.offsetLeft, oy = st.img.offsetTop;
        const qx = (b.x + b.w / 2 - ox - st.x) / st.s, qy = (b.y + b.h / 2 - oy - st.y) / st.s;
        st.s = s;
        st.x = W / 2 - ox - s * qx;
        st.y = H / 2 - oy - s * qy;
        apply(frame, st);
    };
    const showActions = (frame, st, b) => {
        const actions = document.createElement('div');
        actions.className = 'doc-box-actions';
        const make = (label, run) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'button-small';
            btn.textContent = label;
            btn.addEventListener('click', e => { e.stopPropagation(); run(); clearBox(st); });
            actions.append(btn);
        };
        make(st.bar.dataset.labelZoom, () => zoomBox(frame, st, b));
        make(st.bar.dataset.labelCopy, () => copy(st, b));
        st.box.append(actions);
        // Other modules may add their own action for the marked area (e.g. "Text auswählen", modules/ocr-text.js).
        const a = toPage(frame, b.x, b.y), z = toPage(frame, b.x + b.w, b.y + b.h, true);
        if (a && z) {
            DocHamster.emit('viewer:box', { frame, page: pageOf(frame), box: { x: a.x, y: a.y, w: z.x - a.x, h: z.y - a.y }, add: (label, run) => make(label, run) });
        }
    };

    document.addEventListener('wheel', e => {
        const frame = frameOf(e.target);
        if (!frame) return;
        e.preventDefault();
        const st = stateOf(frame);
        clearBox(st);
        zoomAt(frame, st, st.s * Math.exp(-e.deltaY * (e.deltaMode ? 0.05 : 0.0015)), point(frame, e));
    }, { passive: false });

    document.addEventListener('input', e => {
        if (!e.target.matches?.('[data-zoom-range]')) return;
        const frame = frameOf(e.target.closest('[data-doc-zoom]').previousElementSibling);
        if (!frame) return;
        const st = stateOf(frame);
        clearBox(st);
        zoomAt(frame, st, e.target.value / 100, center(frame));
    });

    // Point 25: Bewegen (pan, default) or Markieren (box); Alt+drag marks in either mode.
    // Point 23: a click on the percent value resets to 100 %, like a double click on the slider.
    document.addEventListener('click', e => {
        const mode = e.target.closest?.('[data-zoom-mode]');
        if (mode) {
            const bar = mode.closest('[data-doc-zoom]');
            bar.querySelectorAll('[data-zoom-mode]').forEach(b => b.setAttribute('aria-pressed', String(b === mode)));
            bar.previousElementSibling.classList.toggle('is-selecting', mode.dataset.zoomMode === 'select');
            return;
        }
        const resetBtn = e.target.closest?.('[data-zoom-reset]');
        if (resetBtn) {
            const frame = frameOf(resetBtn.closest('[data-doc-zoom]').previousElementSibling);
            if (frame) { const st = stateOf(frame); clearBox(st); reset(frame, st); }
        }
    });
    document.addEventListener('dblclick', e => {
        if (!e.target.matches?.('[data-zoom-range]')) return;
        const frame = frameOf(e.target.closest('[data-doc-zoom]').previousElementSibling);
        if (frame) { const st = stateOf(frame); clearBox(st); reset(frame, st); }
    });

    document.addEventListener('pointerdown', e => {
        if (e.target.closest?.('.doc-box-actions')) return;
        clearAll();
        const frame = frameOf(e.target);
        if (!frame || e.button !== 0 || e.pointerType === 'touch') return;
        e.preventDefault();
        if (frame.tabIndex >= 0) frame.focus({ preventScroll: true });
        const st = stateOf(frame);
        const select = e.altKey || frame.classList.contains('is-selecting');
        const start = point(frame, e);
        let last = start, box = null;
        frame.setPointerCapture(e.pointerId);
        const move = ev => {
            const p = point(frame, ev);
            if (select) {
                const b = { x: Math.min(start.x, p.x), y: Math.min(start.y, p.y), w: Math.abs(p.x - start.x), h: Math.abs(p.y - start.y) };
                if (!box && b.w < 5 && b.h < 5) return;
                if (!box) { box = document.createElement('div'); box.className = 'doc-box'; frame.append(box); st.box = box; }
                box.b = b;
                Object.assign(box.style, { left: b.x + 'px', top: b.y + 'px', width: b.w + 'px', height: b.h + 'px' });
            } else {
                st.x += p.x - last.x;
                st.y += p.y - last.y;
                last = p;
                frame.classList.add('is-panning');
                apply(frame, st);
            }
        };
        const up = () => {
            frame.removeEventListener('pointermove', move);
            frame.removeEventListener('pointerup', up);
            frame.removeEventListener('pointercancel', up);
            frame.classList.remove('is-panning');
            if (box && box.b.w >= 5 && box.b.h >= 5) showActions(frame, st, box.b);
            else if (box) clearBox(st);
        };
        frame.addEventListener('pointermove', move);
        frame.addEventListener('pointerup', up);
        frame.addEventListener('pointercancel', up);
    });

    document.addEventListener('dblclick', e => {
        const frame = frameOf(e.target);
        if (!frame || e.target.closest('.doc-box-actions')) return;
        const st = stateOf(frame);
        clearBox(st);
        reset(frame, st);
    });

    document.addEventListener('keydown', e => { if (e.key === 'Escape') clearAll(); });

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
    const live = new Set();
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
        const doc = pdf.doc;
        Promise.resolve(doc?.loadingTask?.destroy ? doc.loadingTask.destroy() : doc?.destroy?.()).catch(() => {});
    };
    const sweep = () => live.forEach(frame => { if (!frame.isConnected) drop(frame); });
    const draw = async (frame, pdf) => {
        const gen = ++pdf.gen;
        const st = states.get(frame);
        const level = Math.max(1, st?.s ?? 1);
        let page;
        try { page = await pdf.doc.getPage(pdf.page); } catch { return; }
        if (gen !== pdf.gen || !frame.isConnected) return;
        const one = page.getViewport({ scale: 1 });
        const cs = getComputedStyle(frame);
        const w = frame.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        const h = frame.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
        if (w <= 0 || h <= 0) return; // hidden window: drawn when it gets a size
        let scale = Math.min(w / one.width, h / one.height) * (window.devicePixelRatio || 1) * level;
        const px = one.width * one.height * scale * scale;
        if (px > PDF_MAX_PX) scale *= Math.sqrt(PDF_MAX_PX / px);
        const viewport = page.getViewport({ scale });
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
    const resized = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(entries => entries.forEach(entry => {
        const pdf = pdfs.get(entry.target);
        if (pdf) { pdf.drawn = null; redraw(entry.target, pdf, 150); }
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
    const turn = (frame, step) => {
        const pdf = pdfs.get(frame);
        const to = pdf ? Math.min(pdf.pages, Math.max(1, pdf.page + step)) : 0;
        if (!pdf || to === pdf.page) return;
        show(frame, pdf, to);
    };
    const show = (frame, pdf, to) => {
        pdf.page = to;
        const st = states.get(frame);
        if (st) { clearBox(st); st.s = 1; st.x = 0; st.y = 0; }
        pdf.drawn = null;
        showPages(frame, pdf);
        redraw(frame, pdf, 0);
    };
    const initPdf = async frame => {
        if (frame.dataset.pdfReady !== undefined) return;
        frame.dataset.pdfReady = '';
        sweep();
        let doc;
        try { doc = await openPdf(frame.dataset.pdf); } catch { return; } // the preview image stays
        if (!frame.isConnected) { Promise.resolve(doc.loadingTask?.destroy ? doc.loadingTask.destroy() : doc.destroy?.()).catch(() => {}); /* pdf.js 6: freed via its loading task */ return; }
        const pdf = { doc, page: 1, pages: doc.numPages, gen: 0, drawn: null, timer: 0, task: null };
        pdfs.set(frame, pdf);
        live.add(frame);
        showPages(frame, pdf);
        if (resized) resized.observe(frame); else redraw(frame, pdf, 0);
    };
    DocHamster.on('window:open', root => root.querySelectorAll?.('.detail-doc[data-pdf]').forEach(initPdf));

    // For modules that point at places on the document (modules/ocr-text.js, document:highlight). Pages count from 1;
    // boxes are page-relative {x, y, w, h} (0..1) like the OCR words.
    DocHamster.viewer = {
        // The viewer frame of a window (null when the document has no picture).
        frameIn: win => [...(win?.querySelectorAll?.('.detail-doc') ?? [])].find(frame => frameOf(frame) === frame) ?? null,
        page: pageOf,
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
        layer: layerOf,
        // Page-relative point under a mouse event, null outside the page.
        pointAt: (frame, e) => { const p = point(frame, e); return toPage(frame, p.x, p.y); },
        // Pans a zoomed document so the box is in view.
        reveal: (frame, box) => {
            const st = stateOf(frame);
            const c = content(st);
            if (!c || st.s <= 1) return;
            const fx = st.img.offsetLeft + st.x + st.s * (c.x + (box.x + box.w / 2) * c.pw);
            const fy = st.img.offsetTop + st.y + st.s * (c.y + (box.y + box.h / 2) * c.ph);
            if (fx < 0 || fx > frame.clientWidth) st.x += frame.clientWidth / 2 - fx;
            if (fy < 0 || fy > frame.clientHeight) st.y += frame.clientHeight / 2 - fy;
            apply(frame, st);
        },
        // The shown picture (img or pdf canvas) with its pixel size, for drawing a part of it.
        source: frame => {
            const img = stateOf(frame).img;
            const w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
            return w && h ? { img, w, h } : null;
        },
    };
    DocHamster.on('window:close', root => root.querySelectorAll?.('.detail-doc[data-pdf]').forEach(drop));
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
