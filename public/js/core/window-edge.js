/* Package L */
// Point 44 / A37: the drag edge right of a window changes that window's width (drag, arrow keys, double-click =
// back to the module's optimal width) through the window system's sizer (A91–A94: the windows on the right give
// way). A97: the width belongs to this one window (data-window-hand, kept in the tab's window history); for the
// layout it is the window's preferred width. New windows start at their kind's optimal width.
// Point 52 / A39: the view switcher of a list belongs to its window – it reloads only that window with ?view=…
// and saves the choice as the default for new windows.
(() => {
    const DocHamster = window.DocHamster;
    // The window's own width (null = back to the module's optimal width); sizer.end() stores it in the history.
    const write = (win, width) => {
        if (width === null) {
            delete win.dataset.windowHand;
        } else {
            win.dataset.windowHand = String(Math.round(width));
        }
    };
    // One sizing step through the window system's sizer (A91–A94): it shares the width out with the windows on the
    // right; the width that came out is remembered for this window.
    const size = (win, step) => {
        const sizer = DocHamster.windowSizer?.(win);
        if (!sizer) {
            return;
        }
        const width = step(sizer);
        write(win, width);
        sizer.end();
    };

    const setup = () => {
        const box = document.querySelector('[data-windows]');
        if (!box) {
            return;
        }
        box.classList.add('can-size');

        // Drag: widths come from the start state plus the whole distance (no step-by-step drift); moves are
        // bundled per frame; the sizer lays nothing else out until the pointer is let go.
        box.addEventListener('pointerdown', event => {
            const edge = event.target.closest('[data-window-edge]');
            const win = edge?.closest('section[data-window]');
            if (!edge || event.button !== 0) {
                return;
            }
            const sizer = DocHamster.windowSizer?.(win);
            if (!sizer) {
                return;
            }
            event.preventDefault();
            const x0 = event.clientX;
            let x = x0;
            let frame = 0;
            let width = null;
            const step = () => {
                frame = 0;
                width = sizer.by(x - x0);
            };
            edge.setPointerCapture(event.pointerId);
            edge.classList.add('is-active');
            box.classList.add('is-sizing');
            box.dispatchEvent(new CustomEvent('dh:win-drag', { detail: { phase: 'start', win } }));
            const move = e => {
                x = e.clientX;
                frame ||= requestAnimationFrame(step);
            };
            const done = () => {
                edge.removeEventListener('pointermove', move);
                edge.removeEventListener('pointerup', done);
                edge.removeEventListener('pointercancel', done);
                cancelAnimationFrame(frame);
                if (x !== x0) {
                    step();
                }
                edge.classList.remove('is-active');
                box.classList.remove('is-sizing');
                if (width !== null) {
                    write(win, width);
                }
                sizer.end();
                box.dispatchEvent(new CustomEvent('dh:win-drag', { detail: { phase: 'end', win } }));
            };
            edge.addEventListener('pointermove', move);
            edge.addEventListener('pointerup', done);
            edge.addEventListener('pointercancel', done);
        });
        box.addEventListener('dblclick', event => {
            const win = event.target.closest('[data-window-edge]')?.closest('section[data-window]');
            if (win) {
                write(win, null);
                const sizer = DocHamster.windowSizer?.(win);
                sizer?.to(sizer.pref);
                sizer?.end();
            }
        });
        box.addEventListener('keydown', event => {
            const edge = event.target.closest('[data-window-edge]');
            if (!edge || !['ArrowLeft', 'ArrowRight'].includes(event.key)) {
                return;
            }
            event.preventDefault();
            const step = (event.shiftKey ? 64 : 16) * (event.key === 'ArrowLeft' ? -1 : 1);
            size(edge.closest('section[data-window]'), sizer => sizer.by(step));
        });
    };

    // The view switcher (point 52): the window loads itself again with ?view=… (or ?date=…, point 108, the
    // submit button's name); the POST keeps it as the default.
    const setupViews = () => {
        document.addEventListener('submit', event => {
            const form = event.target.closest('form[data-view-switch]');
            const win = form?.closest('section[data-window]');
            const value = event.submitter?.value;
            const key = event.submitter?.name || 'view';
            if (!form || !win || !value || !DocHamster.reloadWindow) {
                return;
            }
            event.preventDefault();
            const body = new FormData(form);
            body.set(key, value);
            fetch(form.action, { method: 'POST', body, credentials: 'same-origin', redirect: 'manual', headers: { Accept: 'text/html' } })
                .catch(() => { /* only the default for new windows */ });
            const url = new URL(win.dataset.windowUrl ?? location.href, location.href);
            url.searchParams.set(key, value);
            url.searchParams.delete('page');
            DocHamster.reloadWindow(win, url, '.win-frame').catch(() => { location.href = url.href; });
        });
    };

    const start = () => {
        setup();
        setupViews();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
