// Fullscreen viewer (point 168.2): the "Vollbild" button in a detail window's zoom bar clones the window's
// <template data-doc-fullscreen-tpl> into a framework dialog over the whole app (top layer, Esc / X / backdrop close).
// It holds the same frame + zoom bar pair as the detail, so modules/document-viewer.js does wheel/Ctrl+wheel zoom,
// drag to pan, pages and pdf.js, and DocHamster.viewer.frameIn() points the OCR word overlay (modules/ocr-text.js) at
// it while it is open. Here: the zoom buttons, touch pan + pinch, and A344 ← / → through the opener window's rows.
(() => {
    const DocHamster = window.DocHamster;
    const STEP = 1.25;
    let counter = 0;
    let reopen = 0; // A344: the next detail window opened within a few seconds opens in fullscreen again

    const winOf = el => el?.closest?.('section[data-window]') ?? null;
    const openOf = el => el?.closest?.('.doc-fullscreen:not([hidden])') ?? null;
    const frameIn = box => box.querySelector('.doc-fullscreen-frame');

    // A344: the rows of the list this window was opened from (window system: data-window-from = opener's id).
    const rowsOf = win => {
        const from = win?.dataset.windowFrom;
        const opener = from ? document.querySelector(`section[data-window][data-window-id="${CSS.escape(from)}"]`) : null;
        if (!opener) return [];
        return [...opener.querySelectorAll('[data-doc]')].filter(row => !row.closest('[hidden]') && row.querySelector('a[data-window]'));
    };
    const neighbours = (win, id) => {
        const rows = rowsOf(win);
        const at = rows.findIndex(row => row.dataset.doc === id);
        return at < 0 ? null : { prev: rows[at - 1] ?? null, next: rows[at + 1] ?? null };
    };
    const showDocs = (box, win, id) => {
        const near = neighbours(win, id);
        const group = box.querySelector('[data-fs-docs]');
        group.hidden = !near || (!near.prev && !near.next);
        if (near) {
            group.querySelector('[data-fs-doc-step="-1"]').disabled = !near.prev;
            group.querySelector('[data-fs-doc-step="1"]').disabled = !near.next;
        }
    };
    const stepDoc = (box, step) => {
        const win = winOf(box);
        const near = neighbours(win, box.dataset.fsDoc);
        const row = near && (step < 0 ? near.prev : near.next);
        if (!row) return;
        reopen = Date.now();
        row.querySelector('a[data-window]').click(); // the window system opens it in place of this detail window
    };

    const close = box => {
        const frame = frameIn(box);
        const win = winOf(box);
        if (frame) DocHamster.viewer?.release(frame);
        box.remove();
        const detail = DocHamster.viewer?.frameIn(win);
        if (detail) DocHamster.emit('viewer:page', { frame: detail, page: DocHamster.viewer.page(detail) });
    };

    const open = button => {
        const win = winOf(button);
        const tpl = win?.querySelector('template[data-doc-fullscreen-tpl]');
        if (!tpl || openOf(win.querySelector('.doc-fullscreen'))) return;
        const box = tpl.content.firstElementChild.cloneNode(true);
        box.dataset.dialog = `doc-fullscreen-${button.dataset.docFullscreen}-${++counter}`;
        box.dataset.fsDoc = button.dataset.docFullscreen;
        tpl.after(box);
        // core/dialogs.js closes it by setting hidden; then it is freed and removed.
        new MutationObserver((records, observer) => {
            if (box.hidden) { observer.disconnect(); close(box); }
        }).observe(box, { attributes: true, attributeFilter: ['hidden'] });
        box.hidden = false;
        const frame = frameIn(box);
        DocHamster.viewer?.init(frame);
        showDocs(box, win, box.dataset.fsDoc);
        frame.focus({ preventScroll: true });
    };

    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-doc-fullscreen]');
        if (button) { open(button); return; }
        const zoom = event.target.closest?.('[data-fs-zoom]');
        const box = openOf(zoom ?? event.target.closest?.('[data-fs-doc-step]'));
        if (!box) return;
        if (!zoom) { stepDoc(box, Number(event.target.closest('[data-fs-doc-step]').dataset.fsDocStep)); return; }
        const frame = frameIn(box);
        const mode = zoom.dataset.fsZoom;
        if (mode === 'in' || mode === 'out') DocHamster.viewer.zoomBy(frame, mode === 'in' ? STEP : 1 / STEP);
        else DocHamster.viewer.fit(frame, mode);
    });

    // Keys while open: ← / → other document (A344), + / − zoom, 0 whole page. Not while typing (word popup on top).
    document.addEventListener('keydown', event => {
        const box = document.querySelector('.doc-fullscreen:not([hidden])');
        if (!box || event.altKey || event.ctrlKey || event.metaKey || event.target.matches?.('input, textarea, select, [contenteditable]')) return;
        if (event.target.closest?.('.dialog') && !box.contains(event.target)) return;
        const frame = frameIn(box);
        const keys = {
            ArrowLeft: () => stepDoc(box, -1), ArrowRight: () => stepDoc(box, 1),
            '+': () => DocHamster.viewer.zoomBy(frame, STEP), '-': () => DocHamster.viewer.zoomBy(frame, 1 / STEP),
            0: () => DocHamster.viewer.fit(frame, 'page'),
        };
        const run = keys[event.key];
        if (run) { event.preventDefault(); run(); }
    });

    // Touch: one finger pans, two fingers pinch (around their middle). The mouse is handled by document-viewer.js.
    const touches = new Map();
    let pinch = null;
    const mid = () => { const [a, b] = [...touches.values()]; return { clientX: (a.x + b.x) / 2, clientY: (a.y + b.y) / 2, d: Math.hypot(a.x - b.x, a.y - b.y) }; };
    document.addEventListener('pointerdown', event => {
        const frame = event.pointerType === 'touch' ? event.target.closest?.('.doc-fullscreen-frame') : null;
        if (!frame) return;
        frame.setPointerCapture(event.pointerId);
        touches.set(event.pointerId, { x: event.clientX, y: event.clientY, frame });
        pinch = touches.size === 2 ? mid() : null;
    });
    document.addEventListener('pointermove', event => {
        const t = touches.get(event.pointerId);
        if (!t) return;
        const dx = event.clientX - t.x, dy = event.clientY - t.y;
        t.x = event.clientX;
        t.y = event.clientY;
        if (touches.size === 1) { DocHamster.viewer.pan(t.frame, dx, dy); return; }
        if (touches.size === 2 && pinch) {
            const now = mid();
            if (pinch.d > 0 && now.d > 0) DocHamster.viewer.zoomBy(t.frame, now.d / pinch.d, now);
            DocHamster.viewer.pan(t.frame, now.clientX - pinch.clientX, now.clientY - pinch.clientY);
            pinch = now;
        }
    });
    const lift = event => {
        if (!touches.delete(event.pointerId)) return;
        pinch = touches.size === 2 ? mid() : null;
    };
    document.addEventListener('pointerup', lift);
    document.addEventListener('pointercancel', lift);

    // A344: the detail window that replaced this one opens in fullscreen too.
    DocHamster.on('window:open', root => {
        if (!reopen || Date.now() - reopen > 8000) return;
        const button = root.querySelector?.('[data-doc-fullscreen]');
        if (!button) return;
        reopen = 0;
        requestAnimationFrame(() => open(button));
    });
})();
