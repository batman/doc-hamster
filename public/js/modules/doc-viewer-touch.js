// Touch in the detail window's document viewer (point 240, A422): two fingers pinch up to 1000 % around their middle,
// one finger pans while the document is zoomed (unzoomed, the finger scrolls the window as usual – the frame's CSS
// switches touch-action with .is-zoomed). The fullscreen viewer has its own touch handling (modules/doc-fullscreen.js).
(() => {
    const DocHamster = window.DocHamster;
    const touches = new Map();
    let pinch = null;
    const mid = () => {
        const [a, b] = [...touches.values()];
        return { clientX: (a.x + b.x) / 2, clientY: (a.y + b.y) / 2, d: Math.hypot(a.x - b.x, a.y - b.y) };
    };
    const frameOf = el => {
        const frame = el?.closest?.('.detail-doc');
        if (!frame || frame.closest('.doc-fullscreen')) return null;
        return DocHamster.viewer?.frameIn(frame.closest('section[data-window]')) === frame ? frame : null;
    };
    document.addEventListener('pointerdown', event => {
        const frame = event.pointerType === 'touch' ? frameOf(event.target) : null;
        if (!frame) return;
        touches.set(event.pointerId, { x: event.clientX, y: event.clientY, frame });
        pinch = touches.size === 2 ? mid() : null;
        if (touches.size === 2 || frame.classList.contains('is-zoomed')) {
            touches.forEach((t, id) => { try { t.frame.setPointerCapture(id); } catch { /* already gone */ } });
        }
    });
    document.addEventListener('pointermove', event => {
        const t = touches.get(event.pointerId);
        if (!t) return;
        const dx = event.clientX - t.x, dy = event.clientY - t.y;
        t.x = event.clientX;
        t.y = event.clientY;
        if (touches.size === 1) {
            if (t.frame.classList.contains('is-zoomed')) DocHamster.viewer.pan(t.frame, dx, dy);
            return;
        }
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
})();
