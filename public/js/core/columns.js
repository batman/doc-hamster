(() => {
    const root = document.documentElement;

    // Settings columns as remembered, before the first paint (Go 2026-11-07, A57): <html> gets
    // data-col0/1 = open|closed and --col0-w/--col1-w; the CSS draws them, so nothing jumps.
    // setupColumns takes over once the page is there and removes these marks again.
    try {
        const cols = JSON.parse(localStorage.getItem('dh-cols')) ?? {};
        const widths = JSON.parse(localStorage.getItem('dh-col-widths')) ?? {};
        const rem = 16;
        [0, 1].forEach(i => {
            if (cols[i] === 'open' || cols[i] === 'closed') {
                root.dataset[`col${i}`] = cols[i];
            }
            if (cols[i] !== 'closed' && typeof widths[i] === 'number') {
                root.style.setProperty(`--col${i}-w`, `${widths[i]}px`);
                if (widths[i] < 12 * rem) {
                    root.dataset[`col${i}Icons`] = '';
                }
            }
        });
    } catch { /* nothing remembered */ }

    // Einstellungen columns: every pane folds to its strip and opens again on its own; the page gives way.
    // The browser remembers each pane's state; Escape folds the pane opened last.
    const setupColumns = () => {
        const cols = document.querySelector('[data-cols]');
        if (!cols) {
            return;
        }
        const COLS = 'dh-cols';
        const pane = i => cols.querySelector(`[data-col="${i}"]`);
        const strip = i => cols.querySelector(`[data-strip="${i}"]`);
        const foldable = i => pane(i) !== null && strip(i) !== null && pane(i).querySelector(`[data-fold="${i}"]`) !== null;
        let state = {};
        try { state = JSON.parse(localStorage.getItem(COLS)) ?? {}; } catch { state = {}; }
        const opened = [];
        const set = (i, open, animate = true) => {
            pane(i).hidden = !open;
            strip(i).hidden = open;
            pane(i).classList.toggle('is-opening', open && animate);
            state[i] = open ? 'open' : 'closed';
            try { localStorage.setItem(COLS, JSON.stringify(state)); } catch { /* private mode: not remembered */ }
            document.dispatchEvent(new Event('dh:cols'));
        };
        [0, 1].forEach(i => {
            if (foldable(i) && state[i] !== undefined) {
                set(i, state[i] === 'open', false);
            }
        });
        cols.addEventListener('animationend', event => event.target.classList.remove('is-opening'));
        const dropEarlyMarks = () => [0, 1].forEach(i => {
            delete root.dataset[`col${i}`];
            delete root.dataset[`col${i}Icons`];
            root.style.removeProperty(`--col${i}-w`);
        });

        // Walls between the columns (Go 2026-11-07, points 11-13): drag a column wider or narrower. Narrow shows
        // only the icons, too narrow snaps to the strip like the arrow; a strip can be dragged open again.
        // Widths are remembered per column; a double click resets, arrow keys move the focused wall.
        const WIDTHS = 'dh-col-widths';
        const rem = parseFloat(getComputedStyle(root).fontSize);
        const ICONS = 4.5 * rem;
        const TEXT = 12 * rem;
        let widths = {};
        try { widths = JSON.parse(localStorage.getItem(WIDTHS)) ?? {}; } catch { widths = {}; }
        const keepWidths = () => { try { localStorage.setItem(WIDTHS, JSON.stringify(widths)); } catch { /* not remembered */ } };
        const desktop = matchMedia('(min-width: 64rem)');
        const limits = i => (pane(i)?.classList.contains('col-pane') ? [16 * rem, 40 * rem] : [ICONS, 24 * rem]);
        const size = (i, width) => {
            const p = pane(i);
            if (width === undefined) {
                p.style.width = '';
                p.classList.remove('is-icons');
            } else {
                p.style.width = `${width}px`;
                p.classList.toggle('is-icons', width < TEXT && !p.classList.contains('col-pane'));
            }
            document.dispatchEvent(new Event('dh:cols'));
        };
        [0, 1].forEach(i => {
            if (foldable(i) && desktop.matches && typeof widths[i] === 'number' && !pane(i).hidden) {
                size(i, widths[i]);
            }
        });
        dropEarlyMarks();
        [0, 1].filter(foldable).forEach(i => {
            const wall = document.createElement('div');
            wall.className = 'col-wall';
            wall.tabIndex = 0;
            wall.setAttribute('role', 'separator');
            wall.setAttribute('aria-orientation', 'vertical');
            wall.setAttribute('aria-label', cols.dataset.wallLabel ?? '');
            pane(i).after(wall);
            const [min, max] = limits(i);
            // width < min snaps to the strip; otherwise the column takes the width, held at the limits (A45)
            const apply = (width, done) => {
                if (width < ICONS) {
                    if (!pane(i).hidden) {
                        set(i, false, false);
                    }
                    if (done) {
                        delete widths[i];
                        keepWidths();
                    }
                    return;
                }
                const w = Math.min(max, Math.max(min, width));
                if (pane(i).hidden) {
                    set(i, true, false);
                }
                size(i, w);
                if (done) {
                    widths[i] = w;
                    keepWidths();
                }
            };
            wall.addEventListener('pointerdown', event => {
                if (event.button !== 0 || !desktop.matches) {
                    return;
                }
                event.preventDefault();
                const left = (pane(i).hidden ? strip(i) : pane(i)).getBoundingClientRect().left;
                wall.setPointerCapture(event.pointerId);
                wall.classList.add('is-active');
                const move = e => apply(e.clientX - left, false);
                wall.addEventListener('pointermove', move);
                wall.addEventListener('pointerup', e => {
                    wall.removeEventListener('pointermove', move);
                    wall.classList.remove('is-active');
                    apply(e.clientX - left, true);
                }, { once: true });
            });
            wall.addEventListener('dblclick', () => {
                if (pane(i).hidden) {
                    set(i, true, false);
                }
                size(i, undefined);
                delete widths[i];
                keepWidths();
            });
            wall.addEventListener('keydown', event => {
                const step = event.key === 'ArrowLeft' ? -rem : (event.key === 'ArrowRight' ? rem : 0);
                if (step !== 0) {
                    event.preventDefault();
                    apply((pane(i).hidden ? 0 : pane(i).offsetWidth) + step, true);
                }
            });
        });

        // Soft width changes (Go 2026-11-07, points 16-19): the arrow opens a column to its widest, folding and the
        // automatic minimizing shrink it to the strip; ease-in-out, nothing moves with "reduce motion".
        const still = matchMedia('(prefers-reduced-motion: reduce)');
        const STRIP = 3.5 * rem;
        const glide = (p, from, to) => new Promise(done => {
            if (still.matches || !desktop.matches) {
                done();
                return;
            }
            p.style.width = `${from}px`;
            p.classList.add('is-gliding');
            p.getBoundingClientRect();
            p.style.width = `${to}px`;
            const end = () => {
                p.classList.remove('is-gliding');
                done();
            };
            p.addEventListener('transitionend', end, { once: true });
            setTimeout(end, 400);
        });
        const fold = i => glide(pane(i), pane(i).offsetWidth, STRIP).then(() => {
            set(i, false, false);
            size(i, undefined);
            delete widths[i];
            keepWidths();
        });
        const unfold = i => {
            const max = limits(i)[1];
            set(i, true, false);
            pane(i).classList.remove('is-icons');
            return glide(pane(i), STRIP, max).then(() => {
                size(i, max);
                widths[i] = max;
                keepWidths();
            });
        };

        // A click on an entry of the groups column folds it to the strip before the next page opens (point 16); the
        // entries column next to the page stays open, so switching between its entries keeps it (point 117).
        cols.addEventListener('click', event => {
            const item = event.target.closest('a.col-item');
            const col = item?.closest('[data-col]');
            if (!item || !col || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) {
                return;
            }
            const i = Number(col.dataset.col);
            if (i !== 0) {
                return;
            }
            state[i] = 'closed';
            delete widths[i];
            keepWidths();
            try { localStorage.setItem(COLS, JSON.stringify(state)); } catch { /* not remembered */ }
            // The page change itself is soft (view transitions in the CSS, A58): the column glides into its strip.
        });

        cols.addEventListener('click', event => {
            const folder = event.target.closest('[data-fold]');
            if (folder) {
                const i = Number(folder.dataset.fold);
                fold(i).then(() => strip(i).focus());
                return;
            }
            const opener = event.target.closest('[data-strip]');
            if (opener) {
                const i = Number(opener.dataset.strip);
                opened.push(i);
                unfold(i).then(() => pane(i).querySelector('[data-fold]')?.focus());
            }
        });
        // Capture phase: runs before dialogs and dropdowns close themselves on the same key.
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape' || root.classList.contains('nav-open')
                || document.querySelector('[data-dialog]:not([hidden]), .dropdown-panel:not([hidden])')) {
                return;
            }
            while (opened.length > 0) {
                const i = opened.pop();
                if (!pane(i).hidden) {
                    fold(i).then(() => strip(i).focus());
                    return;
                }
            }
        }, { capture: true });
    };

    document.addEventListener('DOMContentLoaded', setupColumns);
})();
