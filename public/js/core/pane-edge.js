/* Pane edge (point 168.1): a drag edge below a pane (`.pane[data-pane="key"]`) changes its height – drag, arrow keys
   (Shift = bigger steps), the reset icon `[data-pane-reset="key"]` (and, as the one allowed double-click, a double-click
   on the edge). Min 12rem, max 85 % of the window's height. The height belongs to the KIND (key), not to one record:
   localStorage `dh.pane.<key>`, set as `--pane-<key>` on <html> while this file runs in <head>, so a window draws at
   its height at once (no jump). The pane reads it via `style="--pane-size: var(--pane-<key>)"`. */
(() => {
    const PREFIX = 'dh.pane.';
    const root = document.documentElement;
    const store = (key, px) => {
        try {
            if (px === null) localStorage.removeItem(PREFIX + key); else localStorage.setItem(PREFIX + key, String(Math.round(px)));
        } catch { /* private mode: only for this page */ }
    };
    const set = (key, px) => {
        if (px === null) root.style.removeProperty(`--pane-${key}`);
        else root.style.setProperty(`--pane-${key}`, `${Math.round(px)}px`);
    };
    try {
        for (let i = 0; i < localStorage.length; i++) {
            const name = localStorage.key(i);
            const px = Number(localStorage.getItem(name));
            if (name?.startsWith(PREFIX) && px > 0) set(name.slice(PREFIX.length), px);
        }
    } catch { /* no storage: default heights */ }

    const rem = () => parseFloat(getComputedStyle(root).fontSize) || 16;
    const paneOf = edge => {
        let el = edge.previousElementSibling;
        while (el && !el.matches(`.pane[data-pane="${edge.dataset.paneEdge}"]`)) el = el.previousElementSibling;
        return el;
    };
    const limits = pane => {
        const box = pane.closest('section[data-window]') ?? document.body;
        return { min: 12 * rem(), max: Math.max(12 * rem(), 0.85 * (box.clientHeight || innerHeight)) };
    };
    const resize = (edge, px, save = true) => {
        const pane = paneOf(edge);
        if (!pane) return;
        const { min, max } = limits(pane);
        px = Math.min(max, Math.max(min, px));
        set(edge.dataset.paneEdge, px);
        edge.setAttribute('aria-valuenow', String(Math.round(px)));
        if (save) store(edge.dataset.paneEdge, px);
    };
    const reset = key => { set(key, null); store(key, null); };

    document.addEventListener('pointerdown', event => {
        const edge = event.target.closest?.('[data-pane-edge]');
        const pane = edge && paneOf(edge);
        if (!pane || event.button !== 0) return;
        event.preventDefault();
        const y0 = event.clientY, h0 = pane.getBoundingClientRect().height;
        let frame = 0, y = y0;
        edge.setPointerCapture(event.pointerId);
        edge.classList.add('is-active');
        const move = e => {
            y = e.clientY;
            frame ||= requestAnimationFrame(() => { frame = 0; resize(edge, h0 + y - y0, false); });
        };
        const done = () => {
            edge.removeEventListener('pointermove', move);
            edge.removeEventListener('pointerup', done);
            edge.removeEventListener('pointercancel', done);
            cancelAnimationFrame(frame);
            edge.classList.remove('is-active');
            if (y !== y0) resize(edge, h0 + y - y0);
        };
        edge.addEventListener('pointermove', move);
        edge.addEventListener('pointerup', done);
        edge.addEventListener('pointercancel', done);
    });
    document.addEventListener('keydown', event => {
        const edge = event.target.closest?.('[data-pane-edge]');
        const pane = edge && paneOf(edge);
        if (!pane || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        resize(edge, pane.getBoundingClientRect().height + (event.shiftKey ? 64 : 16) * (event.key === 'ArrowUp' ? -1 : 1));
    });
    // The edge exception of the double-click rule: back to the default height (the reset icon does the same).
    document.addEventListener('dblclick', event => {
        const edge = event.target.closest?.('[data-pane-edge]');
        if (edge) reset(edge.dataset.paneEdge);
    });
    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-pane-reset]');
        if (button) reset(button.dataset.paneReset);
    });
})();
