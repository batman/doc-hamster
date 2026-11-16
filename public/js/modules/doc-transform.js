// Point 238: turn and crop the shown page in the detail window (zoom bar group [data-doc-transform]), saved at once.
// Non-destructive (A414): the rotation (clockwise, 0/90/180/270) and the crop box [x, y, w, h] (page-relative, in the
// original unrotated page – App\Domain\Documents\PageTransform: crop first, then turn) live in data-transforms on every
// viewer frame of the window, by page number (A416). The document viewer asks DocHamster.viewer.shaper for the shown
// size and picture (img or pdf.js canvas); the server keeps OCR word boxes in shown coordinates, so the overlay lines
// up, makes the thumbnail again and reads the text again (A415, A417).
(() => {
    const DocHamster = window.DocHamster;
    const MIN = 0.02; // smallest crop side, PageTransform::MIN_SIDE
    const cropping = new WeakMap(); // frame → { page, t, box, el } while the crop frame is up
    const active = new Set();
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));

    // --- Transforms ------------------------------------------------------------------------------------------------
    const read = frame => { try { return JSON.parse(frame?.dataset.transforms || '{}') || {}; } catch { return {}; } };
    const norm = t => ({ r: (((Number(t?.r) || 0) % 360) + 360) % 360, c: Array.isArray(t?.c) && t.c.length === 4 ? t.c.map(Number) : null });
    const saved = (frame, page) => norm(read(frame)[page]);
    const isNone = t => !t.r && !t.c;
    const same = (a, b) => JSON.stringify(norm(a)) === JSON.stringify(norm(b));
    // While cropping, the page shows turned but uncropped – the crop frame goes over the whole page.
    const shownOf = (frame, page) => {
        const t = saved(frame, page);
        return cropping.get(frame)?.page === page ? { r: t.r, c: null } : t;
    };
    // Original ↔ shown page point, as PageTransform::toShown / toOriginal.
    const toShown = (t, u, v) => {
        const [cx, cy, cw, ch] = t.c ?? [0, 0, 1, 1];
        const a = (u - cx) / cw, b = (v - cy) / ch;
        return t.r === 90 ? [1 - b, a] : t.r === 180 ? [1 - a, 1 - b] : t.r === 270 ? [b, 1 - a] : [a, b];
    };
    const toOriginal = (t, s, q) => {
        const [a, b] = t.r === 90 ? [q, 1 - s] : t.r === 180 ? [1 - s, 1 - q] : t.r === 270 ? [1 - q, s] : [s, q];
        const [cx, cy, cw, ch] = t.c ?? [0, 0, 1, 1];
        return [cx + a * cw, cy + b * ch];
    };
    const mapBox = (fn, t, b) => {
        const [x1, y1] = fn(t, b.x, b.y), [x2, y2] = fn(t, b.x + b.w, b.y + b.h);
        return { x: Math.min(x1, x2), y: Math.min(y1, y2), w: Math.abs(x2 - x1), h: Math.abs(y2 - y1) };
    };
    // The shown picture: the crop part of the source, turned clockwise, as a canvas (the source itself without transform).
    const paint = (t, src) => {
        const W = src.naturalWidth || src.width, H = src.naturalHeight || src.height;
        if (isNone(t) || !W || !H) return src;
        const [cx, cy, cw, ch] = t.c ?? [0, 0, 1, 1];
        const x = clamp(Math.round(cx * W), 0, W - 1), y = clamp(Math.round(cy * H), 0, H - 1);
        const w = clamp(Math.round(cw * W), 1, W - x), h = clamp(Math.round(ch * H), 1, H - y);
        const out = document.createElement('canvas');
        const side = t.r % 180 !== 0;
        out.width = side ? h : w;
        out.height = side ? w : h;
        const ctx = out.getContext('2d');
        ctx.translate(out.width / 2, out.height / 2);
        ctx.rotate(t.r * Math.PI / 180);
        ctx.drawImage(src, x, y, w, h, -w / 2, -h / 2, w, h);
        out.className = 'thumb doc-canvas';
        return out;
    };
    if (DocHamster.viewer) {
        DocHamster.viewer.shaper = {
            size: (frame, page, w, h) => {
                const t = shownOf(frame, page);
                const sw = (t.c?.[2] ?? 1) * w, sh = (t.c?.[3] ?? 1) * h;
                return t.r % 180 ? { w: sh, h: sw } : { w: sw, h: sh };
            },
            paint: (frame, page, src) => paint(shownOf(frame, page), src),
        };
    }

    // --- Frames and saving -----------------------------------------------------------------------------------------
    const groupOf = frame => frame?.nextElementSibling?.querySelector?.('[data-doc-transform]') ?? null;
    const frameOfBar = el => {
        const frame = el?.closest?.('[data-doc-zoom]')?.previousElementSibling;
        return frame?.matches('.detail-doc') ? frame : null;
    };
    // Every viewer frame of the window, also the one in the fullscreen viewer's template.
    const framesIn = win => [
        ...win.querySelectorAll('.detail-doc[data-transforms]'),
        ...[...win.querySelectorAll('template[data-doc-fullscreen-tpl]')].flatMap(tpl => [...tpl.content.querySelectorAll('.detail-doc[data-transforms]')]),
    ];
    const syncReset = frame => {
        const reset = groupOf(frame)?.querySelector('[data-transform-reset]');
        if (reset) reset.hidden = isNone(saved(frame, DocHamster.viewer.page(frame)));
    };
    const setAll = (win, page, t) => framesIn(win).forEach(frame => {
        const all = read(frame);
        if (isNone(t)) delete all[page]; else all[page] = { r: t.r, c: t.c };
        frame.dataset.transforms = JSON.stringify(all);
        if (!frame.isConnected) return;
        if (DocHamster.viewer.page(frame) === page) DocHamster.viewer.refresh(frame);
        syncReset(frame);
    });
    const toast = (text, error = false) => { if (text) DocHamster.toast?.(text, { error }); };
    // The thumbnail got a new address (DocumentFile::thumbUrl): lists, tiles and templates show the new one.
    const newThumb = (fileId, url) => {
        const part = `/files/${fileId}/thumb`;
        const swap = img => { if (img.getAttribute('src')?.includes(part)) img.src = url; };
        document.querySelectorAll('img').forEach(swap);
        document.querySelectorAll('template').forEach(tpl => tpl.content.querySelectorAll('img').forEach(swap));
    };
    const save = (frame, page, t) => {
        const group = groupOf(frame);
        if (!group) return;
        const win = frame.closest('section[data-window]') ?? document;
        const before = saved(frame, page);
        if (same(t, before)) { DocHamster.viewer.refresh(frame); return; }
        setAll(win, page, t);
        const body = new FormData();
        body.append('page', String(page));
        body.append('rotation', String(t.r));
        if (t.c) ['x', 'y', 'w', 'h'].forEach((key, i) => body.append(`crop[${key}]`, String(t.c[i])));
        fetch(group.dataset.docTransform, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' } })
            .then(response => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then(json => {
                // The server may have clamped the crop box a little: its value counts.
                if (!same(json.transform, t)) setAll(win, page, norm(json.transform));
                if (json.thumb && json.file) newThumb(json.file, json.thumb);
                toast(json.message);
                DocHamster.emit('ocr-text:changed', { documentId: group.dataset.document });
            })
            .catch(() => { setAll(win, page, before); toast(group.dataset.labelFailed, true); });
    };

    // --- Crop frame ------------------------------------------------------------------------------------------------
    // PO 2026-11-16: ✓ and X stand outside the marked area – below its right edge; no room below, above it; only when the
    // frame covers (nearly) the whole page, inside its bottom right corner.
    const place = (el, b) => {
        Object.assign(el.style, { left: b.x * 100 + '%', top: b.y * 100 + '%', width: b.w * 100 + '%', height: b.h * 100 + '%' });
        const room = el.parentElement?.getBoundingClientRect().height ?? 0;
        const below = (1 - b.y - b.h) * room >= 52;
        const above = !below && b.y * room >= 52;
        el.classList.toggle('is-bar-above', above);
        el.classList.toggle('is-bar-inside', !below && !above);
    };
    const pressed = (frame, on) => groupOf(frame)?.querySelector('[data-transform-crop]')?.setAttribute('aria-pressed', String(on));
    const startCrop = (frame, page) => {
        const tpl = groupOf(frame)?.querySelector('template[data-crop-tpl]');
        if (!tpl) return;
        const t = saved(frame, page);
        const turned = { r: t.r, c: null };
        const box = t.c ? mapBox(toShown, turned, { x: t.c[0], y: t.c[1], w: t.c[2], h: t.c[3] }) : { x: 0, y: 0, w: 1, h: 1 };
        const el = tpl.content.firstElementChild.cloneNode(true);
        cropping.set(frame, { page, t, box, el });
        active.add(frame);
        DocHamster.viewer.refresh(frame); // the whole page, turned
        const layerPage = DocHamster.viewer.layer(frame);
        // The frame has buttons: the layer is no longer hidden from screen readers while it is up.
        layerPage.parentElement.classList.add('is-cropping');
        layerPage.parentElement.removeAttribute('aria-hidden');
        layerPage.append(el);
        place(el, box);
        pressed(frame, true);
        el.querySelector('[data-crop-apply]')?.focus({ preventScroll: true });
    };
    const endCrop = (frame, show = true) => {
        const st = cropping.get(frame);
        if (!st) return;
        cropping.delete(frame);
        active.delete(frame);
        const layer = st.el.closest('.doc-layer');
        st.el.remove();
        layer?.classList.remove('is-cropping');
        layer?.setAttribute('aria-hidden', 'true');
        pressed(frame, false);
        if (show) DocHamster.viewer.refresh(frame);
    };
    const applyCrop = frame => {
        const st = cropping.get(frame);
        if (!st) return;
        const o = mapBox(toOriginal, { r: st.t.r, c: null }, st.box);
        endCrop(frame, false);
        const whole = o.x <= 0.0005 && o.y <= 0.0005 && o.x + o.w >= 0.9995 && o.y + o.h >= 0.9995;
        save(frame, st.page, { r: st.t.r, c: whole ? null : [o.x, o.y, o.w, o.h].map(v => Math.round(v * 1e6) / 1e6) });
    };
    // A handle moves its edges (at least MIN apart, inside the page); the frame itself moves as a whole.
    const resize = (b, handle, dx, dy) => {
        if (handle === 'move') return { ...b, x: clamp(b.x + dx, 0, 1 - b.w), y: clamp(b.y + dy, 0, 1 - b.h) };
        let x1 = b.x, y1 = b.y, x2 = b.x + b.w, y2 = b.y + b.h;
        if (handle.includes('w')) x1 = clamp(x1 + dx, 0, x2 - MIN);
        if (handle.includes('e')) x2 = clamp(x2 + dx, x1 + MIN, 1);
        if (handle.includes('n')) y1 = clamp(y1 + dy, 0, y2 - MIN);
        if (handle.includes('s')) y2 = clamp(y2 + dy, y1 + MIN, 1);
        return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
    };
    // Before the viewer (capture): a drag on the crop frame is no pan and no box selection.
    document.addEventListener('pointerdown', e => {
        const el = e.target.closest?.('.crop-box');
        if (!el) return;
        e.stopPropagation();
        if (e.target.closest('.crop-actions') || e.button !== 0) return;
        e.preventDefault();
        const st = cropping.get(el.closest('.detail-doc'));
        if (!st) return;
        const handle = e.target.closest('.crop-handle')?.dataset.handle ?? 'move';
        const rect = el.parentElement.getBoundingClientRect(); // the shown page (.doc-layer-page), zoom included
        const rel = ev => ({ x: (ev.clientX - rect.left) / rect.width, y: (ev.clientY - rect.top) / rect.height });
        const start = rel(e), from = { ...st.box };
        el.setPointerCapture(e.pointerId);
        const move = ev => {
            const p = rel(ev);
            st.box = resize(from, handle, p.x - start.x, p.y - start.y);
            place(el, st.box);
        };
        const up = () => {
            el.removeEventListener('pointermove', move);
            el.removeEventListener('pointerup', up);
            el.removeEventListener('pointercancel', up);
        };
        el.addEventListener('pointermove', move);
        el.addEventListener('pointerup', up);
        el.addEventListener('pointercancel', up);
    }, true);

    // --- Buttons and keys ------------------------------------------------------------------------------------------
    document.addEventListener('click', e => {
        const btn = e.target.closest?.('[data-transform-rotate], [data-transform-reset], [data-transform-crop], [data-crop-apply], [data-crop-cancel]');
        if (!btn) return;
        if (btn.matches('[data-crop-apply], [data-crop-cancel]')) {
            const frame = btn.closest('.detail-doc');
            if (btn.matches('[data-crop-apply]')) applyCrop(frame); else endCrop(frame);
            return;
        }
        const frame = frameOfBar(btn);
        if (!frame) return;
        const page = DocHamster.viewer.page(frame);
        if (btn.matches('[data-transform-crop]')) {
            if (cropping.has(frame)) endCrop(frame); else startCrop(frame, page);
            return;
        }
        endCrop(frame, false);
        const t = saved(frame, page);
        save(frame, page, btn.matches('[data-transform-reset]') ? { r: 0, c: null } : { r: (t.r + Number(btn.dataset.transformRotate) + 360) % 360, c: t.c });
    });
    // Escape cancels, Enter applies (not while typing elsewhere).
    document.addEventListener('keydown', e => {
        if ((e.key !== 'Escape' && e.key !== 'Enter') || !active.size) return;
        if (e.target.matches?.('input, textarea, select, [contenteditable]')) return;
        [...active].forEach(frame => {
            if (!frame.isConnected) { active.delete(frame); return; }
            if (e.key === 'Escape') { endCrop(frame); return; }
            if (frame.contains(e.target) || frame.nextElementSibling?.contains(e.target) || e.target === document.body) {
                e.preventDefault();
                applyCrop(frame);
            }
        });
    });

    // Another page of a PDF: a crop frame of the old page goes; the reset button follows the page.
    DocHamster.on('viewer:page', ({ frame, page } = {}) => {
        if (!frame) return;
        const st = cropping.get(frame);
        if (st && st.page !== page) endCrop(frame, false);
        syncReset(frame);
    });
    // A picture with a rotation/crop is turned as soon as it is there (pdf.js pages ask the shaper while drawing).
    DocHamster.on('window:open', root => root.querySelectorAll?.('.detail-doc[data-transforms]').forEach(frame => {
        if (!frame.dataset.pdf && !isNone(saved(frame, 1))) DocHamster.viewer?.refresh(frame);
        syncReset(frame);
    }));
})();
