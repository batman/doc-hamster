/* Documents, details view: column widths by dragging the line between two heads (point 225, A390, A391). The handle
   [data-col-resize] sits at the right edge of a head [data-col]. Dragging widens or narrows the column on its left; the
   name column has no fixed width (it takes the rest), so at its edge the column on the right changes instead. Widths
   are remembered in this browser for every folder and applied through one constructed style sheet for all windows (a
   <style> element would be blocked by the CSP, style-src 'self'; CSSOM sheets are allowed). No double-click. */
(() => {
    const KEY = 'dh-doc-cols';
    const MIN = 60;
    let widths = {};
    try {
        widths = JSON.parse(localStorage.getItem(KEY) ?? '{}') ?? {};
    } catch { widths = {}; }
    const sheet = new CSSStyleSheet();
    const paint = () => {
        sheet.replaceSync(Object.entries(widths)
            .filter(([col, px]) => /^[\w:-]+$/.test(col) && Number.isFinite(px))
            .map(([col, px]) => `.view-details th[data-col="${col}"] { width: ${Math.round(px)}px; }`)
            .join('\n'));
    };
    paint();
    document.adoptedStyleSheets = [...document.adoptedStyleSheets, sheet];
    const save = () => {
        try {
            localStorage.setItem(KEY, JSON.stringify(widths));
        } catch { /* only for this page */ }
    };

    let swallow = false;
    document.addEventListener('pointerdown', event => {
        const handle = event.target.closest?.('[data-col-resize]');
        if (!handle || event.button !== 0) return;
        event.preventDefault();
        event.stopPropagation();
        const head = handle.closest('th[data-col]');
        // At the name column's edge the next head changes (it shrinks while the name grows).
        const right = head.dataset.col === 'name';
        const target = right ? head.nextElementSibling : head;
        if (!target?.dataset.col) return;
        const start = event.clientX;
        const from = target.getBoundingClientRect().width;
        document.documentElement.classList.add('is-col-resizing');
        try { handle.setPointerCapture(event.pointerId); } catch { /* a pointer the browser no longer tracks */ }
        const move = e => {
            const dx = e.clientX - start;
            widths[target.dataset.col] = Math.max(MIN, from + (right ? -dx : dx));
            paint();
        };
        const up = () => {
            handle.removeEventListener('pointermove', move);
            document.documentElement.classList.remove('is-col-resizing');
            save();
            swallow = true;
            setTimeout(() => { swallow = false; }, 0);
        };
        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', up, { once: true });
        handle.addEventListener('pointercancel', up, { once: true });
    }, true);
    // The click that ends a drag must not sort by the column.
    document.addEventListener('click', event => {
        if (swallow || event.target.closest?.('[data-col-resize]')) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);
})();
