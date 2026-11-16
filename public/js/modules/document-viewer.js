// Document viewer in the detail window: wheel zoom towards the mouse, drag to pan, slider 25-1000 %, box selection with
// "Zoomen" / "Kopieren"; the percent button goes back to fit (point 169: no double-click actions).
// Delegated, so windows loaded later work too; the fullscreen viewer (modules/doc-fullscreen.js) is the same frame + bar pair.
// PDFs: modules/doc-viewer-pdf.js (pdf.js canvas in place of the picture); touch: modules/doc-viewer-touch.js.
// Point 240: zoom up to 1000 % (A420); the slider is logarithmic (A421), so 25-200 % take more than half of it.
(() => {
    const MIN = 0.25, MAX = 10;
    // A421: slider 0..1000 ↔ zoom MIN..MAX on a log scale.
    const SLIDER = 1000;
    const toSlider = s => Math.round(SLIDER * Math.log(s / MIN) / Math.log(MAX / MIN));
    const fromSlider = v => MIN * Math.pow(MAX / MIN, v / SLIDER);
    const states = new WeakMap();
    const pdfs = new WeakMap(); // filled by modules/doc-viewer-pdf.js
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
    const core = { pdfs, states, stateOf, apply: null, clearBox: null, zoomed: null };
    const apply = (frame, st) => {
        clamp(frame, st);
        st.img.style.transformOrigin = '0 0';
        st.img.style.transform = st.s === 1 && !st.x && !st.y ? '' : `translate(${st.x}px, ${st.y}px) scale(${st.s})`;
        frame.classList.toggle('is-zoomed', st.s > 1);
        const pct = Math.round(st.s * 100);
        const range = st.bar.querySelector('[data-zoom-range]');
        if (range) { range.value = toSlider(st.s); range.setAttribute('aria-valuetext', pct + ' %'); }
        const value = st.bar.querySelector('[data-zoom-value]');
        if (value) value.textContent = pct + ' %';
        syncLayer(frame, st);
        core.zoomed?.(frame, st);
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
    const pageOf = frame => DocHamster.viewer.page(frame);
    const reset = (frame, st) => { st.s = 1; st.x = 0; st.y = 0; apply(frame, st); };
    const clearBox = st => { st.box?.remove(); st.box = null; };
    Object.assign(core, { apply, clearBox });
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
    // Zoom so the box (frame coords) fills the frame, centred; `most` caps the zoom.
    const zoomBox = (frame, st, b, most = MAX) => {
        const W = frame.clientWidth, H = frame.clientHeight;
        const s = Math.min(most, MAX, Math.max(MIN, st.s * Math.min(W / b.w, H / b.h)));
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
        // Other modules may add their own action for the marked area (e.g. "Markieren als …", modules/ocr-text.js).
        const a = toPage(frame, b.x, b.y), z = toPage(frame, b.x + b.w, b.y + b.h, true);
        if (a && z) {
            DocHamster.emit('viewer:box', { frame, page: pageOf(frame), box: { x: a.x, y: a.y, w: z.x - a.x, h: z.y - a.y }, add: (label, run) => make(label, run) });
        }
    };

    // Wheel and Ctrl+wheel (trackpad pinch) zoom towards the pointer (A422).
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
        zoomAt(frame, st, fromSlider(Number(e.target.value)), center(frame));
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

    document.addEventListener('keydown', e => { if (e.key === 'Escape') clearAll(); });

    // For modules that point at places on the document (modules/ocr-text.js, document:highlight). Pages count from 1;
    // boxes are page-relative {x, y, w, h} (0..1) like the OCR words. goTo/init/release come from doc-viewer-pdf.js.
    DocHamster.viewer = {
        _core: core,
        // The viewer frame of a window (null when the document has no picture); while the window's fullscreen viewer
        // is open (point 168.2), that one – so word hover, selection and highlights follow it there.
        frameIn: win => {
            const full = win?.querySelector?.('.doc-fullscreen:not([hidden]) .detail-doc');
            if (full && frameOf(full) === full) return full;
            return [...(win?.querySelectorAll?.('.detail-doc') ?? [])].find(frame => frameOf(frame) === frame && !frame.closest('.doc-fullscreen')) ?? null;
        },
        page: () => 1,
        goTo: (frame, page) => Promise.resolve(page === 1),
        init: () => undefined,
        release: () => undefined,
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
        // Point 239 "Im Beleg zeigen": zoom and pan so the box (with room around it) stands in the middle – at most
        // 250 %, or the present zoom when that is larger.
        focus: (frame, box) => {
            const st = stateOf(frame);
            const c = content(st);
            if (!c) return;
            clearBox(st);
            const pad = 3; // the box with room around it
            const fx = st.img.offsetLeft + st.x + st.s * (c.x + box.x * c.pw), fy = st.img.offsetTop + st.y + st.s * (c.y + box.y * c.ph);
            const fw = st.s * box.w * c.pw, fh = st.s * box.h * c.ph;
            const b = { x: fx - fw * (pad - 1) / 2, y: fy - fh * (pad - 1) / 2, w: fw * pad, h: fh * pad };
            zoomBox(frame, st, b, Math.max(st.s, 2.5));
        },
        // The shown picture (img or pdf canvas) with its pixel size, for drawing a part of it.
        source: frame => {
            const img = stateOf(frame).img;
            const w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
            return w && h ? { img, w, h } : null;
        },
        // Point 238: shows the page again after its rotation/crop changed (back to fit). A PDF is drawn again by
        // doc-viewer-pdf.js (core.repaint); a picture is turned from the loaded <img data-raw>, which is kept while a
        // canvas stands in its place.
        refresh: frame => {
            const st = stateOf(frame);
            clearBox(st);
            st.s = 1; st.x = 0; st.y = 0;
            if (pdfs.has(frame)) { core.repaint?.(frame); return; }
            const raw = st.raw ??= frame.querySelector(':scope > img.thumb[data-raw]');
            if (!raw) return;
            if (!raw.complete || !raw.naturalWidth) { raw.addEventListener('load', () => DocHamster.viewer.refresh(frame), { once: true }); return; }
            const shown = DocHamster.viewer.shaper?.paint(frame, 1, raw) ?? raw;
            if (shown !== st.img) { st.img.replaceWith(shown); st.img = shown; }
            apply(frame, st);
            DocHamster.emit('viewer:page', { frame, page: 1 });
        },
        scale: frame => stateOf(frame).s,
        // Zoom by a factor around a client point (default: the frame's centre).
        zoomBy: (frame, factor, at = null) => {
            const st = stateOf(frame);
            clearBox(st);
            zoomAt(frame, st, st.s * factor, at ? point(frame, at) : center(frame));
        },
        pan: (frame, dx, dy) => { const st = stateOf(frame); st.x += dx; st.y += dy; apply(frame, st); },
        // 'page' = whole page (fit), 'width' = page width fills the frame (top shown), 'actual' = 100 % (CSS pixels).
        fit: (frame, mode) => {
            const st = stateOf(frame);
            clearBox(st);
            const c = content(st);
            if (mode === 'page' || !c) { reset(frame, st); return; }
            if (mode === 'width') {
                st.s = Math.min(MAX, Math.max(MIN, frame.clientWidth / c.pw));
                st.x = -st.img.offsetLeft - st.s * c.x;
                st.y = -st.img.offsetTop - st.s * c.y;
                apply(frame, st);
                return;
            }
            const pdf = pdfs.get(frame);
            const natural = pdf?.width ? pdf.width * 96 / 72 : (st.img.naturalWidth || st.img.width);
            zoomAt(frame, st, natural / c.pw, center(frame));
        },
    };
})();
