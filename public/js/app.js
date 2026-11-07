// Side menu, receipt upload, dropdowns and the receipt filter (layouts/app).
// Loaded in <head> so the saved menu state applies before the first paint;
// the rest waits for the DOM.
(() => {
    const root = document.documentElement;
    const KEY = 'dh-nav';
    const read = () => { try { return localStorage.getItem(KEY); } catch { return null; } };
    const save = value => { try { localStorage.setItem(KEY, value); } catch { /* private mode: not remembered */ } };

    const saved = read();
    if (saved === 'closed' || saved === 'rail') {
        root.classList.add(`nav-${saved}`);
    }

    const setupMenu = () => {
        const nav = document.getElementById('nav');
        if (!nav) {
            return;
        }
        const toggle = document.querySelector('.nav-toggle');
        const collapse = nav.querySelector('.nav-collapse');
        // Same breakpoint as app.css: below it the menu is a drawer over the page.
        const mobile = matchMedia('(max-width: 63.99rem)');

        const sync = () => {
            const open = mobile.matches ? root.classList.contains('nav-open') : !root.classList.contains('nav-closed');
            toggle.setAttribute('aria-expanded', String(open));
            collapse.setAttribute('aria-label', root.classList.contains('nav-rail') ? collapse.dataset.expand : collapse.dataset.collapse);
        };
        const store = () => save(root.classList.contains('nav-closed') ? 'closed' : (root.classList.contains('nav-rail') ? 'rail' : 'open'));
        const closeDrawer = () => {
            root.classList.remove('nav-open');
            sync();
        };

        toggle.addEventListener('click', () => {
            if (mobile.matches) {
                root.classList.toggle('nav-open');
            } else {
                root.classList.toggle('nav-closed');
                store();
            }
            sync();
        });
        collapse.addEventListener('click', () => {
            root.classList.toggle('nav-rail');
            store();
            sync();
        });
        document.querySelector('.nav-backdrop').addEventListener('click', closeDrawer);

        // The wall right of the menu (A40): wide = open with that width, narrow = icons only, too narrow = hidden.
        const NAV_WIDTH = 'dh-nav-width';
        const rem = parseFloat(getComputedStyle(root).fontSize);
        const applyWidth = width => root.style.setProperty('--nav-width', width ? `${width}px` : '');
        try { applyWidth(Number(localStorage.getItem(NAV_WIDTH)) || 0); } catch { /* default width */ }
        const side = nav.closest('.side');
        const wall = document.createElement('div');
        wall.className = 'nav-wall';
        wall.setAttribute('aria-hidden', 'true');
        side.append(wall);
        wall.addEventListener('pointerdown', event => {
            if (event.button !== 0 || mobile.matches) {
                return;
            }
            event.preventDefault();
            const left = side.getBoundingClientRect().left;
            wall.setPointerCapture(event.pointerId);
            wall.classList.add('is-active');
            const place = (x, done) => {
                const width = x - left;
                root.classList.toggle('nav-closed', width < 4.5 * rem);
                root.classList.toggle('nav-rail', width >= 4.5 * rem && width < 12 * rem);
                if (width >= 12 * rem) {
                    const w = Math.min(24 * rem, width);
                    applyWidth(w);
                    if (done) {
                        try { localStorage.setItem(NAV_WIDTH, String(Math.round(w))); } catch { /* not remembered */ }
                    }
                }
                if (done) {
                    store();
                    sync();
                }
            };
            const move = e => place(e.clientX, false);
            wall.addEventListener('pointermove', move);
            wall.addEventListener('pointerup', e => {
                wall.removeEventListener('pointermove', move);
                wall.classList.remove('is-active');
                place(e.clientX, true);
            }, { once: true });
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && root.classList.contains('nav-open')) {
                closeDrawer();
                toggle.focus();
            }
        });
        mobile.addEventListener('change', closeDrawer);
        sync();

        // Upload starts right after choosing; files dropped anywhere on the page go the same way.
        const form = nav.querySelector('.nav-upload');
        if (!form) {
            return;
        }
        const input = form.querySelector('input[type="file"]');
        const overlay = document.querySelector('.drop-overlay');
        const send = () => {
            if (input.files.length === 0) {
                return;
            }
            form.querySelector('.nav-text').textContent = form.dataset.busy;
            form.classList.add('is-busy');
            overlay.querySelector('p').textContent = form.dataset.busy;
            overlay.hidden = false;
            form.submit();
        };
        input.addEventListener('change', send);

        const carriesFiles = event => event.dataTransfer !== null && [...event.dataTransfer.types].includes('Files');
        const carriesAppPreview = event => event.dataTransfer !== null
            && [...event.dataTransfer.types].includes('application/x-dochamster-preview');
        let depth = 0; // dragenter/dragleave fire for every child element
        // Never turn a dragged document preview into a fresh upload, even if a browser
        // still starts a native drag despite the non-draggable image and CSS rules.
        document.addEventListener('dragstart', event => {
            if (!(event.target instanceof Element) || !event.target.closest('.thumb-frame img') || !event.dataTransfer) {
                return;
            }
            event.dataTransfer.setData('application/x-dochamster-preview', '1');
            event.preventDefault();
        }, true);
        document.addEventListener('dragenter', event => {
            if (carriesFiles(event)) {
                depth++;
                overlay.hidden = false;
            }
        });
        document.addEventListener('dragleave', event => {
            if (carriesFiles(event) && --depth <= 0) {
                depth = 0;
                overlay.hidden = true;
            }
        });
        document.addEventListener('dragover', event => {
            if (carriesFiles(event)) {
                event.preventDefault();
            }
        });
        document.addEventListener('drop', event => {
            if (carriesAppPreview(event)) {
                event.preventDefault();
                depth = 0;
                overlay.hidden = true;
                return;
            }
            if (!carriesFiles(event)) {
                return;
            }
            event.preventDefault();
            depth = 0;
            input.files = event.dataTransfer.files;
            send();
        });
    };

    // Toolbar dropdowns (filter, view): the icon button opens its panel; Escape and a click outside close it.
    const setupDropdowns = () => {
        const dropdowns = [...document.querySelectorAll('.dropdown')];
        const setOpen = (dropdown, open) => {
            dropdown.querySelector('.dropdown-panel').hidden = !open;
            dropdown.querySelector('.icon-button').setAttribute('aria-expanded', String(open));
        };
        const openOne = () => dropdowns.find(dropdown => !dropdown.querySelector('.dropdown-panel').hidden);

        dropdowns.forEach(dropdown => dropdown.querySelector('.icon-button').addEventListener('click', () => {
            const open = dropdown.querySelector('.dropdown-panel').hidden;
            dropdowns.forEach(other => setOpen(other, false));
            setOpen(dropdown, open);
        }));
        document.addEventListener('click', event => {
            if (!event.target.closest('.dropdown')) {
                dropdowns.forEach(dropdown => setOpen(dropdown, false));
            }
        });
        document.addEventListener('keydown', event => {
            const open = openOne();
            if (event.key === 'Escape' && open) {
                setOpen(open, false);
                open.querySelector('.icon-button').focus();
            }
        });
    };

    // Receipt filter: ticks collect, "Anwenden" filters. A receipt must have all ticked tags.
    // When the page doesn't hold every receipt, the server filters instead.
    const setupFilter = () => {
        const filter = document.querySelector('.dropdown[data-kind="filter"]');
        if (!filter) {
            return;
        }
        const boxes = [...filter.querySelectorAll('input[name="tags[]"]')];
        const reset = filter.querySelector('[data-reset]');

        const apply = () => {
            if (filter.dataset.complete !== '1') {
                filter.submit();
                return;
            }
            const wanted = boxes.filter(box => box.checked).map(box => box.value);
            let shown = 0;
            document.querySelectorAll('[data-item]').forEach(item => {
                const has = item.dataset.tags.split(' ');
                item.hidden = !wanted.every(tag => has.includes(tag));
                shown += item.hidden ? 0 : 1;
            });
            document.querySelectorAll('[data-group]').forEach(group => {
                group.hidden = group.querySelector('[data-item]:not([hidden])') === null;
            });
            const none = document.querySelector('[data-no-match]');
            if (none) {
                none.hidden = shown > 0 || wanted.length === 0;
            }

            const active = wanted.length > 0;
            filter.querySelector('.icon-button').classList.toggle('is-active', active);
            filter.querySelector('[data-icon="off"]').hidden = active;
            filter.querySelector('[data-icon="on"]').hidden = !active;
            const badge = filter.querySelector('.badge');
            badge.textContent = String(wanted.length);
            badge.hidden = !active;

            // Keep the address in step, so reloading or a shared link shows the same.
            const url = new URL(location.href);
            url.searchParams.delete('tags[]');
            wanted.forEach(tag => url.searchParams.append('tags[]', tag));
            history.replaceState(null, '', url);
        };

        const close = () => {
            filter.querySelector('.dropdown-panel').hidden = true;
            filter.querySelector('.icon-button').setAttribute('aria-expanded', 'false');
        };
        filter.addEventListener('submit', event => {
            if (filter.dataset.complete === '1') {
                event.preventDefault();
                apply();
                close();
            }
        });
        reset.addEventListener('click', () => {
            boxes.forEach(box => { box.checked = false; });
            apply();
            close();
        });
    };

    // Our own confirm dialog: [data-dialog-open="x"] shows [data-dialog="x"]; Escape, the backdrop and "Abbrechen" close it.
    const setupDialogs = () => {
        const shut = dialog => { dialog.hidden = true; };
        const open = button => {
            const dialog = document.querySelector(`[data-dialog="${button.dataset.dialogOpen}"]`);
            const heading = dialog.querySelector('[data-dialog-heading]');
            if (heading && button.dataset.dialogTitle) {
                heading.textContent = button.dataset.dialogTitle;
            }
            dialog.hidden = false;
            dialog.querySelector('[data-dialog-close], button, a, input')?.focus();
        };
        // The file is fetched ahead: the share menu has to open right on the click.
        const shared = new Map();
        document.querySelectorAll('[data-share]').forEach(button => {
            if (!navigator.canShare) {
                return;
            }
            fetch(button.dataset.share, { credentials: 'same-origin' })
                .then(response => (response.ok ? response.blob() : null))
                .then(blob => {
                    const file = blob && new File([blob], button.dataset.shareName, { type: button.dataset.shareType });
                    if (file && navigator.canShare({ files: [file] })) {
                        shared.set(button, file);
                    }
                })
                .catch(() => { /* no share menu then, our dialog stays */ });
        });
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-dialog-open]');
            if (!button || button.disabled) {
                return;
            }
            const file = shared.get(button);
            if (file) {
                navigator.share({ files: [file], title: file.name }).catch(error => {
                    if (error.name !== 'AbortError') {
                        open(button);
                    }
                });
                return;
            }
            open(button);
        });
        document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', () => {
            const field = document.getElementById(button.dataset.copy);
            field.select();
            navigator.clipboard?.writeText(field.value).then(() => { button.textContent = button.dataset.copied; });
        }));
        // Checklists save with every tick.
        document.querySelectorAll('form[data-autosubmit]').forEach(form => form.addEventListener('change', () => form.submit()));
        document.querySelectorAll('[data-dialog]').forEach(dialog => {
            dialog.addEventListener('click', event => {
                if (event.target === dialog || event.target.closest('[data-dialog-close]')) {
                    shut(dialog);
                }
            });
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                document.querySelectorAll('[data-dialog]:not([hidden])').forEach(shut);
            }
        });
    };

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

        // A click on an entry folds its column to the strip before the next page opens (point 16).
        cols.addEventListener('click', event => {
            const item = event.target.closest('a.col-item');
            const col = item?.closest('[data-col]');
            if (!item || !col || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) {
                return;
            }
            const i = Number(col.dataset.col);
            state[i] = 'closed';
            delete widths[i];
            keepWidths();
            try { localStorage.setItem(COLS, JSON.stringify(state)); } catch { /* not remembered */ }
            if (desktop.matches && !still.matches && foldable(i)) {
                event.preventDefault();
                fold(i).then(() => { location.href = item.href; });
            }
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

    // Desktop: a shade at the left/right edge while the content can scroll further that way.
    const setupScrollHint = () => {
        const content = document.querySelector('.side:not(.side-plain) ~ .content');
        if (!content) {
            return;
        }
        const desktop = matchMedia('(min-width: 64rem)');
        const update = () => {
            root.style.setProperty('--content-left', `${content.getBoundingClientRect().left}px`);
            root.style.setProperty('--sbw', `${content.offsetWidth - content.clientWidth}px`);
            root.style.setProperty('--sbh', `${content.offsetHeight - content.clientHeight}px`);
            const end = content.scrollWidth - content.clientWidth;
            root.classList.toggle('more-left', desktop.matches && content.scrollLeft > 1);
            root.classList.toggle('more-right', desktop.matches && content.scrollLeft < end - 1);
        };
        content.addEventListener('scroll', update, { passive: true });
        document.addEventListener('dh:cols', update);
        new ResizeObserver(update).observe(content);
        update();
    };

    // Menu badges: the numbers come again about once a minute while the page is visible.
    const setupBadges = () => {
        const side = document.querySelector('[data-badges]');
        if (!side) {
            return;
        }
        const show = counts => {
            let any = 0;
            side.querySelectorAll('[data-badge]').forEach(badge => {
                const count = counts[badge.dataset.badge] ?? 0;
                badge.textContent = count > 9 ? '9+' : String(count);
                badge.hidden = count === 0;
                any += count;
            });
            side.querySelector('[data-badge-any]').hidden = any === 0;
        };
        const poll = () => {
            if (document.hidden) {
                return;
            }
            fetch(side.dataset.badges, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(response => (response.ok ? response.json() : null))
                .then(counts => counts && show(counts))
                .catch(() => { /* offline or logged out: keep what is shown */ });
        };
        setInterval(poll, 60000);
        document.addEventListener('visibilitychange', poll);
    };

    // Notes board (Home): on wide screens the notes sit where they were dragged; new ones take the next free slot.
    // Dragging by the head saves the place.
    const setupNotes = () => {
        const board = document.querySelector('[data-notes]');
        if (!board) {
            return;
        }
        const notes = [...board.querySelectorAll('.note')];
        const wide = matchMedia('(min-width: 64rem)');
        const GAP = 24;
        notes.forEach(note => {
            if (note.dataset.opacity) {
                note.style.setProperty('--note-alpha', `${note.dataset.opacity}%`);
            }
        });
        // Edit dialog of a locked note: unticking "Gesperrt" frees the fields right away.
        document.querySelectorAll('[data-note-lock]').forEach(box => {
            const fields = box.form.querySelector('[data-note-fields]');
            const locked = fields.disabled;
            box.addEventListener('change', () => { fields.disabled = locked && box.checked; });
        });

        const fit = () => {
            board.style.height = `${Math.max(0, ...notes.map(note => note.offsetTop + note.offsetHeight)) + GAP}px`;
        };
        const layout = () => {
            board.classList.toggle('is-board', wide.matches);
            if (!wide.matches) {
                // Phones: full width, the saved size waits for the board (A3).
                notes.forEach(note => { note.style.left = ''; note.style.top = ''; note.style.width = ''; note.style.height = ''; });
                board.style.height = '';
                return;
            }
            notes.filter(note => note.dataset.w !== undefined).forEach(note => {
                note.style.width = `${note.dataset.w}px`;
                note.style.height = `${note.dataset.h}px`;
            });
            const taken = [];
            const put = (note, x, y) => {
                note.style.left = `${x}px`;
                note.style.top = `${y}px`;
                taken.push({ x, y, w: note.offsetWidth, h: note.offsetHeight });
            };
            const free = (x, y, w, h) => taken.every(r => x + w + GAP / 2 <= r.x || r.x + r.w + GAP / 2 <= x || y + h + GAP / 2 <= r.y || r.y + r.h + GAP / 2 <= y);
            notes.filter(note => note.dataset.x !== undefined).forEach(note => put(note, Number(note.dataset.x), Number(note.dataset.y)));
            notes.filter(note => note.dataset.x === undefined).forEach(note => {
                const w = note.offsetWidth;
                const h = note.offsetHeight;
                const cols = Math.max(1, Math.floor((board.clientWidth + GAP) / (w + GAP)));
                for (let i = 0; i < 500; i++) {
                    const x = (i % cols) * (w + GAP);
                    const y = Math.floor(i / cols) * (h + GAP);
                    if (free(x, y, w, h) || i === 499) {
                        put(note, x, y);
                        break;
                    }
                }
            });
            fit();
        };

        let layer = 10;
        board.addEventListener('pointerdown', event => {
            const head = event.target.closest('.note-head');
            const note = head?.closest('[data-note-look]');
            if (!note || !wide.matches || event.button !== 0 || event.target.closest('button, a, input, form, .dropdown')) {
                return;
            }
            event.preventDefault();
            const startX = event.clientX - note.offsetLeft;
            const startY = event.clientY - note.offsetTop;
            let moved = false;
            note.style.zIndex = String(++layer);
            note.classList.add('is-dragging');
            head.setPointerCapture(event.pointerId);
            const move = e => {
                const x = Math.min(Math.max(0, e.clientX - startX), Math.max(0, board.clientWidth - note.offsetWidth));
                const y = Math.max(0, e.clientY - startY);
                note.style.left = `${x}px`;
                note.style.top = `${y}px`;
                moved = true;
            };
            const done = () => {
                head.removeEventListener('pointermove', move);
                note.classList.remove('is-dragging');
                fit();
                if (!moved) {
                    return;
                }
                note.dataset.x = String(Math.round(note.offsetLeft));
                note.dataset.y = String(Math.round(note.offsetTop));
                const body = new FormData();
                body.append('_token', board.dataset.csrf);
                body.append('x', note.dataset.x);
                body.append('y', note.dataset.y);
                fetch(note.dataset.noteLook, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                    .catch(() => { /* offline: the place is kept until the next reload */ });
            };
            head.addEventListener('pointermove', move);
            head.addEventListener('pointerup', done, { once: true });
            head.addEventListener('pointercancel', done, { once: true });
        });

        // Size (Go of 2026-11-07): the browser's corner handle sets width/height inline; a moment after the last change it is saved.
        const send = (url, fields) => {
            const body = new FormData();
            body.append('_token', board.dataset.csrf);
            Object.entries(fields).forEach(([key, value]) => body.append(key, value));
            return fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        };
        const timers = new Map();
        const sized = new ResizeObserver(entries => entries.forEach(({ target: note }) => {
            if (!wide.matches || !note.style.width || !note.style.height) {
                return;
            }
            const w = String(Math.round(note.offsetWidth));
            const h = String(Math.round(note.offsetHeight));
            if (w === note.dataset.w && h === note.dataset.h) {
                return;
            }
            clearTimeout(timers.get(note));
            timers.set(note, setTimeout(() => {
                note.dataset.w = w;
                note.dataset.h = h;
                fit();
                send(note.dataset.noteLook, { w, h }).catch(() => { /* offline: kept until the next reload */ });
            }, 500));
        }));
        notes.filter(note => note.dataset.noteLook).forEach(note => sized.observe(note));

        // Edit dialog: colour and opacity show on the note right away; closing without saving puts the old look back (A9).
        document.querySelectorAll('[data-note-preview]').forEach(dialog => {
            const note = board.querySelector(`[data-note-id="${dialog.dataset.notePreview}"]`);
            const form = dialog.querySelector('form');
            if (!note || !form) {
                return;
            }
            const colors = [...form.querySelectorAll('input[name="color"]')].map(input => `note-${input.value}`);
            let before = null;
            let saved = false;
            const show = (color, alpha) => {
                note.classList.remove(...colors);
                note.classList.add(color);
                note.style.setProperty('--note-alpha', alpha);
            };
            form.addEventListener('input', () => {
                const color = form.querySelector('input[name="color"]:checked');
                const opacity = form.querySelector('input[name="opacity"]');
                show(color ? `note-${color.value}` : before.color, opacity ? `${opacity.value}%` : before.alpha);
            });
            form.addEventListener('submit', () => { saved = true; });
            new MutationObserver(() => {
                if (!dialog.hidden) {
                    before = { color: colors.find(c => note.classList.contains(c)) ?? 'note-yellow', alpha: note.style.getPropertyValue('--note-alpha') || '100%' };
                    saved = false;
                } else if (before && !saved) {
                    show(before.color, before.alpha);
                    form.reset();
                }
            }).observe(dialog, { attributes: true, attributeFilter: ['hidden'] });
        });

        // Text (Go of 2026-11-07, round 2): the note's text is always a text field in a monospace font.
        // Lists "[a, b, #c]" are set one entry per line; a mirror with the same font lies exactly over the
        // field and draws our own checkboxes in the first three columns. A click in the text writes right
        // there (the field is underneath), a click on a box ticks. Leaving the field formats and saves.
        const LIST = /\[([^[\]]*)\]/g;
        const entries = inner => inner.split(',')
            .map(part => part.replace(/\s+/g, ' ').trim())
            .map(part => ({ done: part.startsWith('#'), text: (part.startsWith('#') ? part.slice(1) : part).trim() }))
            .filter(entry => entry.text !== '');
        const block = items => items.map((item, i) => `${i === 0 ? '[' : ' '} ${item.done ? '#' : ' '}${item.text}${i === items.length - 1 ? ']' : ','}`).join('\n');
        // After a list the text goes on in the next line.
        const after = rest => {
            const text = rest.replace(/^[ \t]+/, '');
            return text === '' || text.startsWith('\n') ? text : `\n${text}`;
        };
        const format = text => {
            let out = '';
            let pos = 0;
            for (const match of text.matchAll(LIST)) {
                const items = entries(match[1]);
                if (items.length === 0) {
                    continue;
                }
                let before = text.slice(pos, match.index);
                before = (pos > 0 ? after(before) : before).replace(/[ \t]+$/, '');
                if ((out + before) !== '' && !(out + before).endsWith('\n')) {
                    before += '\n';
                }
                out += before + block(items);
                pos = match.index + match[0].length;
            }
            return pos > 0 ? out + after(text.slice(pos)) : text;
        };
        const span = (cls, text) => {
            const el = document.createElement('span');
            el.className = cls;
            el.textContent = text;
            return el;
        };
        // The mirror holds exactly the field's characters; only their look differs.
        const mirror = (wrap, area) => {
            const view = wrap.querySelector('.note-mirror');
            const text = area.value;
            const open = !area.readOnly;
            view.replaceChildren();
            let pos = 0;
            for (const match of text.matchAll(LIST)) {
                if (entries(match[1]).length === 0) {
                    continue;
                }
                view.append(text.slice(pos, match.index));
                let at = match.index;
                match[0].split('\n').forEach((line, i) => {
                    if (i > 0) {
                        view.append('\n');
                        at += 1;
                    }
                    // Our layout: 3 columns for the box, the entry, then "," or "]". Anything else stays plain text.
                    const ok = /^[[ ] [ #]\S/.test(line) && /[,\]]$/.test(line);
                    if (!ok) {
                        view.append(line);
                    } else {
                        const done = line[2] === '#';
                        const slot = span('note-slot', line.slice(0, 3));
                        const box = span(`note-box${done ? ' is-done' : ''}`, '');
                        box.setAttribute('role', 'checkbox');
                        box.setAttribute('aria-checked', String(done));
                        box.setAttribute('aria-label', line.slice(3, -1));
                        box.dataset.at = String(at + 2);
                        if (open) {
                            box.tabIndex = 0;
                        }
                        slot.append(box);
                        view.append(slot, span(`note-entry${done ? ' is-done' : ''}`, line.slice(3, -1)), span('note-mark', line.slice(-1)));
                    }
                    at += line.length;
                });
                pos = match.index + match[0].length;
            }
            view.append(text.slice(pos), '\n');
        };
        const grow = area => {
            area.style.height = 'auto';
            area.style.height = `${area.scrollHeight}px`;
        };
        const save = (wrap, area) => {
            if (area.value === area.dataset.saved || !wrap.dataset.noteText) {
                return;
            }
            const body = area.value;
            send(wrap.dataset.noteText, { body })
                .then(response => (response.ok ? (area.dataset.saved = body) : Promise.reject(response)))
                .catch(() => location.reload());
        };
        board.querySelectorAll('.note-text').forEach(wrap => {
            const area = wrap.querySelector('.note-area');
            area.value = format(area.value);
            area.dataset.saved = area.value;
            mirror(wrap, area);
            grow(area);
            area.addEventListener('input', () => grow(area));
            area.addEventListener('keydown', e => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    area.value = area.dataset.saved;
                    area.blur();
                } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    area.blur();
                }
            });
            area.addEventListener('blur', () => {
                area.value = format(area.value);
                mirror(wrap, area);
                grow(area);
                save(wrap, area);
            });
        });
        const tick = box => {
            const wrap = box.closest('.note-text');
            const area = wrap.querySelector('.note-area');
            if (area.readOnly) {
                return;
            }
            const at = Number(box.dataset.at);
            area.value = area.value.slice(0, at) + (area.value[at] === '#' ? ' ' : '#') + area.value.slice(at + 1);
            mirror(wrap, area);
            save(wrap, area);
        };
        // The box must not take the focus from nowhere into the field underneath.
        board.addEventListener('pointerdown', event => {
            if (event.target.closest('.note-box')) {
                event.preventDefault();
            }
        });
        board.addEventListener('click', event => {
            const box = event.target.closest('.note-box');
            if (box) {
                tick(box);
            }
        });
        board.addEventListener('keydown', event => {
            const box = event.target.closest('.note-box');
            if (box && (event.key === ' ' || event.key === 'Enter')) {
                event.preventDefault();
                tick(box);
                board.querySelector(`.note-box[data-at="${box.dataset.at}"]`)?.focus();
            }
        });
        addEventListener('resize', () => board.querySelectorAll('.note-area').forEach(grow));
        new ResizeObserver(() => board.querySelectorAll('.note-area').forEach(grow)).observe(board);

        wide.addEventListener('change', layout);
        addEventListener('resize', () => wide.matches && fit());
        layout();
    };

    // Toasts close themselves after a few seconds; a click closes them at once.
    const setupToasts = () => {
        document.querySelectorAll('[data-toast]').forEach(toast => {
            const leave = () => {
                toast.classList.add('is-leaving');
                setTimeout(() => toast.remove(), 300);
            };
            toast.addEventListener('click', leave);
            setTimeout(leave, 5000);
        });
    };

    // Update page: a row's "Installieren" puts its pull request number into the password dialog (A54).
    const setupPreviewPick = () => {
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-preview-pr]');
            const field = document.querySelector('[data-preview-pr-field]');
            if (button && field) {
                field.value = button.dataset.previewPr;
            }
        }, { capture: true });
    };

    // Forms with data-ajax="#target" send in the background and put the returned list into the target,
    // so one search never wipes the result of another (Update page). The button spins meanwhile.
    const setupAjaxForms = () => {
        document.querySelectorAll('form[data-ajax]').forEach(form => form.addEventListener('submit', event => {
            event.preventDefault();
            const button = form.querySelector('button[type="submit"]');
            const target = document.querySelector(form.dataset.ajax);
            button.disabled = true;
            button.classList.add('is-busy');
            fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(response => (response.ok ? response.json() : Promise.reject(response)))
                .then(data => {
                    target.innerHTML = data.html;
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                })
                .catch(() => form.submit())
                .finally(() => {
                    button.disabled = false;
                    button.classList.remove('is-busy');
                });
        }));
    };

    // Tags page: the search narrows the pills while typing, any letters inside the name.
    const setupTagSearch = () => {
        const search = document.querySelector('[data-tag-search]');
        if (!search) {
            return;
        }
        search.addEventListener('input', () => {
            const wanted = search.value.trim().toLowerCase();
            document.querySelectorAll('[data-tag-name]').forEach(item => {
                item.hidden = !item.dataset.tagName.includes(wanted);
            });
        });
    };

    // Tag colour: a palette colour, or any colour from the picker or typed as #rrggbb.
    const setupColorField = () => {
        const field = document.querySelector('[data-color-field]');
        if (!field) {
            return;
        }
        const picker = field.querySelector('[data-color-picker]');
        const hex = field.querySelector('[data-color-hex]');
        const radios = [...field.querySelectorAll('input[type="radio"]')];
        const own = value => {
            radios.forEach(radio => { radio.checked = false; });
            hex.value = value;
        };
        picker.addEventListener('input', () => own(picker.value));
        hex.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) {
                picker.value = hex.value.toLowerCase();
            }
            radios.forEach(radio => { radio.checked = false; });
        });
        radios.forEach(radio => radio.addEventListener('change', () => {
            hex.value = '';
            if (radio.value.startsWith('#')) {
                picker.value = radio.value;
            }
        }));
    };

    // Print: the file goes into a hidden frame of our own page and the browser's print dialog opens (nothing leaves the server).
    // Zoom: the viewer loads its file only when it opens; images zoom by buttons, Ctrl + wheel, double click and + / -.
    const setupPrintAndZoom = () => {
        document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => {
            const frame = document.createElement('iframe');
            frame.className = 'print-frame';
            frame.setAttribute('aria-hidden', 'true');
            frame.tabIndex = -1;
            frame.addEventListener('load', () => {
                try {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                } catch {
                    window.open(button.dataset.print, '_blank', 'noopener');
                }
            });
            frame.src = button.dataset.print;
            document.body.append(frame);
            setTimeout(() => frame.remove(), 120000);
        }));

        const viewer = document.querySelector('[data-dialog="viewer"]');
        if (!viewer) {
            return;
        }
        const image = viewer.querySelector('[data-zoom-image]');
        const level = viewer.querySelector('[data-zoom-level]');
        const MIN = 0.25;
        const MAX = 8;
        let zoom = 1;
        const apply = next => {
            zoom = Math.min(MAX, Math.max(MIN, next));
            if (image) {
                image.style.width = `${zoom * 100}%`;
                level.textContent = `${Math.round(zoom * 100)} %`;
            }
        };
        const factor = { in: 1.25, out: 0.8 };
        viewer.querySelectorAll('[data-zoom]').forEach(button => button.addEventListener('click', () => {
            apply(button.dataset.zoom === 'fit' ? 1 : zoom * factor[button.dataset.zoom]);
        }));
        image?.addEventListener('dblclick', () => apply(zoom > 1 ? 1 : 2.5));
        viewer.addEventListener('wheel', event => {
            if (image && event.ctrlKey) {
                event.preventDefault();
                apply(zoom * (event.deltaY < 0 ? 1.1 : 0.9));
            }
        }, { passive: false });
        document.addEventListener('keydown', event => {
            if (viewer.hidden || !image || event.ctrlKey || event.metaKey) {
                return;
            }
            if (event.key === '+' || event.key === '=') {
                apply(zoom * 1.25);
            } else if (event.key === '-') {
                apply(zoom * 0.8);
            } else if (event.key === '0') {
                apply(1);
            }
        });
        document.querySelectorAll('[data-dialog-open="viewer"]').forEach(button => button.addEventListener('click', () => {
            viewer.querySelectorAll('[data-src]').forEach(element => {
                if (!element.getAttribute('src')) {
                    element.setAttribute('src', element.dataset.src);
                }
            });
            apply(1);
        }));
    };

    // A scan through a Scan Station takes a while: the scan page looks again every few seconds until it is over.
    const setupScanWait = () => {
        if (document.querySelector('[data-scan-wait]')) {
            setTimeout(() => window.location.reload(), 3000);
        }
    };

    // ---- Phone app (PHONE, issue #37) -------------------------------------------------

    // Home-screen app: the service worker keeps the app's files and the offline page (public/sw.js).
    const setupServiceWorker = () => {
        const meta = document.querySelector('meta[name="dh-sw"]');
        if (!meta || !('serviceWorker' in navigator) || !window.isSecureContext) {
            return;
        }
        navigator.serviceWorker.register(meta.content).catch(() => { /* works without it, only no offline page */ });
    };

    // Without the queue (no IndexedDB, private mode): put the files into the form and send it the normal way.
    const submitDirectly = (form, files) => {
        const input = form.querySelector('input[type="file"]');
        if (input && typeof DataTransfer === 'function') {
            const transfer = new DataTransfer();
            files.forEach(file => transfer.items.add(file));
            input.files = transfer.files;
        }
        form.submit();
    };

    // Photos are made smaller before sending: a receipt stays easy to read at 2000 pixels,
    // the upload is quick and fits the server's limits. PDFs and anything the phone
    // cannot draw are sent as they are.
    const shrinkPhoto = file => new Promise(resolve => {
        if (!file.type.startsWith('image/') || file.type === 'image/gif') {
            resolve(file);
            return;
        }
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
            URL.revokeObjectURL(url);
            const scale = Math.min(1, 2000 / Math.max(image.naturalWidth, image.naturalHeight));
            if (scale === 1 && file.type === 'image/jpeg' && file.size < 900 * 1024) {
                resolve(file);
                return;
            }
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(image.naturalWidth * scale);
            canvas.height = Math.round(image.naturalHeight * scale);
            canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
            canvas.toBlob(blob => {
                const name = (file.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg';
                resolve(blob ? new File([blob], name, { type: 'image/jpeg' }) : file);
            }, 'image/jpeg', 0.85);
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(file);
        };
        image.src = url;
    });

    // Photos from the app go into a queue on the phone first (IndexedDB) and are sent
    // from there: without a connection they wait and go out when it is back. "Gesendet"
    // only when the server answers 201 (stored); a refusal shows its reason, the photo
    // stays and can be sent again or thrown away.
    // Returns enqueue(form, files) for the camera, or a plain form submit without the queue.
    const setupUploadQueue = () => {
        const forms = [...document.querySelectorAll('form[data-queue-upload]')];
        const status = document.querySelector('.queue-status');
        if (!('indexedDB' in window) || !status) {
            return async (form, files) => submitDirectly(form, await Promise.all(files.map(shrinkPhoto)));
        }
        const text = status.querySelector('.queue-text');
        const retry = status.querySelector('[data-queue-retry]');
        const drop = status.querySelector('[data-queue-drop]');
        let hamster = null;
        let hideTimer = null;
        const show = (message, mood, failed = false) => {
            clearTimeout(hideTimer);
            text.textContent = message;
            status.hidden = false;
            status.classList.toggle('is-failed', failed);
            retry.hidden = !failed;
            drop.hidden = !failed;
            const host = status.querySelector('.queue-hamster');
            if (!hamster && window.docHamster && host) {
                hamster = window.docHamster.mount(host, { mood: mood || 'awake' });
            } else if (hamster && mood) {
                hamster.mood(mood);
            }
        };
        const hideLater = () => {
            hideTimer = setTimeout(() => { status.hidden = true; }, 3500);
        };

        const open = () => new Promise((resolve, reject) => {
            const request = indexedDB.open('dochamster', 1);
            request.onupgradeneeded = () => request.result.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
        const store = async mode => (await open()).transaction('queue', mode).objectStore('queue');
        const done = request => new Promise((resolve, reject) => {
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
        const all = async () => done((await store('readonly')).getAll());
        const add = async item => done((await store('readwrite')).add(item));
        const remove = async id => done((await store('readwrite')).delete(id));

        const tokenFrom = html => new DOMParser().parseFromString(html, 'text/html').querySelector('meta[name="csrf-token"]')?.content || null;
        let token = document.querySelector('meta[name="csrf-token"]')?.content || null;
        const freshToken = async () => {
            const response = await fetch(status.dataset.home, { credentials: 'same-origin', cache: 'no-store' });
            token = tokenFrom(await response.text());
            return token;
        };
        const waiting = count => (count === 1 ? status.dataset.waiting : status.dataset.waitingMany).replace(/\d+/, String(count));
        const reason = async response => {
            if (response.status === 413) {
                return status.dataset.tooBig;
            }
            try {
                const answer = await response.json();
                const first = answer.errors ? Object.values(answer.errors)[0] : null;
                return (Array.isArray(first) ? first[0] : null) || answer.message || status.dataset.failed;
            } catch {
                return status.dataset.failed;
            }
        };

        let busy = false;
        let failedId = null;
        const flush = async () => {
            if (busy) {
                return;
            }
            busy = true;
            failedId = null;
            let sent = 0;
            let refused = null;
            try {
                for (const item of await all()) {
                    if (!navigator.onLine) {
                        break;
                    }
                    show(status.dataset.sending, 'eat');
                    const post = async () => {
                        const body = new FormData();
                        body.append('_token', token || await freshToken() || '');
                        body.append('files[]', item.blob, item.name);
                        if (item.folder) {
                            body.append('folder', item.folder);
                        }
                        return fetch(item.action, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    };
                    let response = await post();
                    if (response.status === 419) { // the session was renewed meanwhile
                        await freshToken();
                        response = await post();
                    }
                    if (response.status === 401 || (response.redirected && new URL(response.url).pathname.endsWith('/login'))) {
                        show(status.dataset.login, 'sleep', false);
                        return;
                    }
                    if (response.status === 201) {
                        await remove(item.id);
                        sent++;
                        continue;
                    }
                    if (response.status >= 400 && response.status < 500) {
                        // The server said no: sending it again the same way will not help.
                        refused = await reason(response);
                        failedId = item.id;
                        break;
                    }
                    throw new Error(`upload ${response.status}`); // server away or broken: try later
                }
            } catch {
                // offline or the server is away: the photos stay in the queue
            } finally {
                busy = false;
            }
            const left = (await all()).length;
            if (refused !== null) {
                show(refused, 'sleep', true);
            } else if (left > 0) {
                show(waiting(left), 'sleep');
            } else if (sent > 0) {
                show(status.dataset.sent, 'awake');
                hideLater();
            }
        };
        retry.addEventListener('click', () => flush());
        drop.addEventListener('click', async () => {
            if (failedId !== null) {
                await remove(failedId);
                failedId = null;
            }
            status.hidden = true;
            flush();
        });

        const enqueue = async (form, files) => {
            const folder = form.querySelector('input[name="folder"]')?.value || null;
            const photos = await Promise.all(files.map(shrinkPhoto));
            try {
                for (const file of photos) {
                    await add({ action: form.action, name: file.name || 'foto.jpg', type: file.type, blob: file, folder, added: Date.now() });
                }
            } catch {
                submitDirectly(form, photos); // no storage on this phone: send directly
                return;
            }
            flush();
        };

        forms.forEach(form => form.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', () => {
                const files = [...input.files];
                input.value = '';
                if (files.length > 0) {
                    enqueue(form, files);
                }
            });
        }));
        window.addEventListener('online', flush);
        flush();
        return enqueue;
    };

    // iPhone and iPad: home-screen apps there forget the camera permission and ask on every
    // start, so the phone's own camera is used instead (one tap on the shutter, no question).
    const isApple = () => /iPhone|iPad|iPod/.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    // Belege: the camera. Elsewhere the live camera is open at once; one tap takes the
    // picture and hands it to the queue, the camera stays open. No live camera (iPhone,
    // no permission): the shutter opens the phone's own camera.
    const setupCamera = enqueue => {
        const box = document.querySelector('[data-live-camera]');
        const form = document.querySelector('[data-camera-form]');
        if (!box || !form) {
            return;
        }
        const video = box.querySelector('[data-camera-view]');
        const hint = box.querySelector('[data-camera-hint]');
        const ready = box.querySelector('[data-camera-ready]');
        const allow = box.querySelector('[data-camera-allow]');
        const flash = box.querySelector('[data-camera-flash]');
        const shutter = form.querySelector('[data-shutter]');
        const native = form.querySelector('[data-camera-native]');
        let stream = null;

        const useNative = denied => {
            video.hidden = true;
            hint.hidden = !denied;
            ready.hidden = denied;
        };
        const stop = () => {
            stream?.getTracks().forEach(track => track.stop());
            stream = null;
        };
        const start = async () => {
            if (stream) {
                if (video.paused) {
                    video.play().catch(() => {});
                }
                return;
            }
            if (!navigator.mediaDevices?.getUserMedia || !window.isSecureContext) {
                useNative(false);
                return;
            }
            // First start in a browser that asks: say once how to make it stop asking.
            try {
                const state = await navigator.permissions?.query({ name: 'camera' });
                if (state?.state === 'prompt' && allow) {
                    allow.hidden = false;
                }
            } catch {
                // the browser cannot tell, nothing to show
            }
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1440 } },
                });
                if (allow) {
                    allow.hidden = true;
                }
                video.srcObject = stream;
                video.hidden = false;
                hint.hidden = true;
                ready.hidden = true;
                // The picture must never stand still with a "play" sign: restart it.
                stream.getVideoTracks().forEach(track => track.addEventListener('ended', () => {
                    stop();
                    if (!document.hidden) {
                        start();
                    }
                }));
                await video.play().catch(() => {});
            } catch {
                stop();
                useNative(true);
            }
        };
        video.addEventListener('pause', () => {
            if (stream && !document.hidden) {
                video.play().catch(() => {});
            }
        });

        shutter.addEventListener('click', () => {
            if (!stream || !video.videoWidth) {
                native?.click();
                return;
            }
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            flash.hidden = false;
            setTimeout(() => { flash.hidden = true; }, 150);
            if (navigator.vibrate) {
                navigator.vibrate(30);
            }
            const stamp = new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-');
            canvas.toBlob(blob => {
                if (blob) {
                    enqueue(form, [new File([blob], `beleg-${stamp}.jpg`, { type: 'image/jpeg' })]);
                }
            }, 'image/jpeg', 0.85);
        });

        if (isApple()) {
            useNative(false);
            return;
        }
        // The camera is switched off while the app is in the background.
        document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
        window.addEventListener('pagehide', stop);
        start();
    };

    // Scannen with the phone camera (A35): the photo is made smaller and sent as the next
    // page; then the scan page opens again with the new page in the strip.
    const setupScanPhotos = () => {
        document.querySelectorAll('form[data-scan-photo]').forEach(form => {
            const input = form.querySelector('input[type="file"]');
            const label = form.querySelector('label span');
            input.addEventListener('change', async () => {
                const file = input.files[0];
                if (!file) {
                    return;
                }
                const original = label.textContent;
                label.textContent = form.dataset.busy;
                form.classList.add('is-busy');
                try {
                    const photo = await shrinkPhoto(file);
                    const body = new FormData();
                    body.append('_token', form.querySelector('input[name="_token"]').value);
                    body.append('photo', photo, photo.name || 'seite.jpg');
                    const response = await fetch(form.action, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    if (response.status === 201) {
                        window.location.href = form.querySelector('input[name="app_back"]').value;
                        return;
                    }
                    let message = '';
                    try {
                        message = (await response.json()).message || '';
                    } catch {
                        // no JSON: the plain message below
                    }
                    window.alert(message || response.statusText);
                } catch {
                    window.alert(navigator.onLine ? form.dataset.unreachable : form.dataset.offline);
                }
                label.textContent = original;
                form.classList.remove('is-busy');
                input.value = '';
            });
        });
    };

    // Drucken in the phone app (A34): the phone's own print dialog lists the printers in its
    // Wi-Fi – nothing to set up. Photos are printed from a hidden frame; a PDF goes through
    // the share menu where available (iPhone: "Drucken" there prints every page).
    const setupPhonePrint = () => {
        const printFrame = url => {
            const frame = document.createElement('iframe');
            frame.className = 'print-frame';
            frame.setAttribute('aria-hidden', 'true');
            frame.tabIndex = -1;
            frame.addEventListener('load', () => {
                try {
                    frame.contentWindow.focus();
                    frame.contentWindow.print();
                } catch {
                    window.print();
                }
            });
            frame.src = url;
            document.body.append(frame);
            setTimeout(() => frame.remove(), 120000);
        };
        document.querySelectorAll('[data-phone-print]').forEach(button => button.addEventListener('click', async () => {
            const url = button.dataset.phonePrint;
            const type = button.dataset.printType || '';
            if (type === 'application/pdf' && navigator.canShare) {
                try {
                    const blob = await (await fetch(url, { credentials: 'same-origin' })).blob();
                    const file = new File([blob], `${button.dataset.printTitle || 'dokument'}.pdf`, { type: 'application/pdf' });
                    if (navigator.canShare({ files: [file] })) {
                        await navigator.share({ files: [file], title: button.dataset.printTitle });
                        return;
                    }
                } catch (error) {
                    if (error && error.name === 'AbortError') {
                        return; // closed by the person
                    }
                }
            }
            printFrame(url);
        }));
    };

    // Phone app: "really delete?" before forms marked data-confirm, and sharing a document.
    const setupPhoneActions = () => {
        document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        }));
        document.querySelectorAll('[data-share]').forEach(button => {
            if (!navigator.share) {
                return;
            }
            button.hidden = false;
            button.addEventListener('click', async () => {
                try {
                    const response = await fetch(button.dataset.share, { credentials: 'same-origin' });
                    const blob = await response.blob();
                    const extension = (button.dataset.shareType || '').split('/')[1] || 'pdf';
                    const file = new File([blob], `${button.dataset.shareTitle || 'dokument'}.${extension}`, { type: blob.type });
                    if (navigator.canShare && navigator.canShare({ files: [file] })) {
                        await navigator.share({ files: [file], title: button.dataset.shareTitle });
                    } else {
                        await navigator.share({ title: button.dataset.shareTitle, url: button.dataset.share });
                    }
                } catch {
                    // cancelled by the person, nothing to do
                }
            });
        });
    };

    // Folder menu under Belege (PO, 2026-11-07): open/close subfolders; drag the handle to change the order of one level.
    // Pointer events, so it works with mouse, pen and finger alike; the new order is saved right away.
    const setupFolderMenu = () => {
        const menu = document.querySelector('.nav-sub');
        if (!menu) {
            return;
        }
        menu.querySelectorAll('[data-folder-toggle]').forEach(button => button.addEventListener('click', () => {
            const item = button.closest('.nav-folder');
            const open = item.classList.toggle('is-open');
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
        }));

        const url = menu.dataset.folderOrder;
        if (!url) {
            return;
        }
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const save = list => {
            const body = new FormData();
            body.append('parent_id', list.dataset.parent);
            [...list.children].forEach(item => body.append('order[]', item.dataset.id));
            fetch(url, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, credentials: 'same-origin' })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }
                })
                .catch(() => {
                    window.alert(menu.dataset.orderFailed);
                    window.location.reload();
                });
        };

        menu.querySelectorAll('[data-grip]').forEach(grip => {
            grip.addEventListener('pointerdown', event => {
                if (event.button !== 0) {
                    return;
                }
                event.preventDefault();
                const item = grip.closest('.nav-folder');
                const list = item.parentElement;
                const before = [...list.children].map(child => child.dataset.id).join();
                // Listened on the document: moving the item in the DOM ends any pointer capture on the handle.
                item.classList.add('is-dragging');
                document.body.classList.add('is-dragging-folder');

                const move = moveEvent => {
                    if (moveEvent.pointerId !== event.pointerId) {
                        return;
                    }
                    const siblings = [...list.children].filter(child => child !== item);
                    const next = siblings.find(child => {
                        const box = child.querySelector('.nav-folder-row').getBoundingClientRect();
                        return moveEvent.clientY < box.top + box.height / 2;
                    });
                    if (next) {
                        if (item.nextElementSibling !== next) {
                            list.insertBefore(item, next);
                        }
                    } else if (list.lastElementChild !== item) {
                        list.appendChild(item);
                    }
                };
                const end = endEvent => {
                    if (endEvent.pointerId !== event.pointerId) {
                        return;
                    }
                    document.removeEventListener('pointermove', move);
                    document.removeEventListener('pointerup', end);
                    document.removeEventListener('pointercancel', end);
                    item.classList.remove('is-dragging');
                    document.body.classList.remove('is-dragging-folder');
                    if ([...list.children].map(child => child.dataset.id).join() !== before) {
                        save(list);
                    }
                };
                document.addEventListener('pointermove', move);
                document.addEventListener('pointerup', end);
                document.addEventListener('pointercancel', end);
            });
            // Keyboard: arrow up/down moves the folder one place.
            grip.addEventListener('keydown', event => {
                if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
                    return;
                }
                event.preventDefault();
                const item = grip.closest('.nav-folder');
                const list = item.parentElement;
                if (event.key === 'ArrowUp' && item.previousElementSibling) {
                    list.insertBefore(item, item.previousElementSibling);
                } else if (event.key === 'ArrowDown' && item.nextElementSibling) {
                    list.insertBefore(item.nextElementSibling, item);
                } else {
                    return;
                }
                grip.focus();
                save(list);
            });
        });
    };

    // Folder import: the picked folder becomes a new folder of the same name; files go up one by one with their path.
    const setupFolderImport = () => {
        const form = document.querySelector('[data-folder-import]');
        if (!form) {
            return;
        }
        const input = form.querySelector('input[type="file"]');
        const status = form.querySelector('[data-import-status]');
        const bar = form.querySelector('progress');
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const text = (key, values = {}) => Object.entries(values).reduce((out, [name, value]) => out.replaceAll(':' + name, String(value)), form.dataset[key] ?? '');
        if (!('webkitdirectory' in input)) {
            status.textContent = text('unsupported');
            input.disabled = true;
            return;
        }
        const wanted = /\.(pdf|jpe?g|png|gif|webp|heic|heif|tiff?|bmp)$/i;
        const post = async (url, body) => {
            const response = await fetch(url, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, credentials: 'same-origin' });
            const data = await response.json().catch(() => ({}));
            return { ok: response.ok, status: response.status, data };
        };

        input.addEventListener('change', async () => {
            const all = [...input.files].filter(file => file.webkitRelativePath && !file.webkitRelativePath.split('/').some(part => part.startsWith('.')));
            if (all.length === 0) {
                status.textContent = text('empty');
                return;
            }
            const files = all.filter(file => wanted.test(file.name) || file.type.startsWith('image/') || file.type === 'application/pdf');
            let skipped = all.length - files.length;
            input.disabled = true;
            bar.hidden = false;
            bar.max = files.length || 1;
            bar.value = 0;

            const start = new FormData();
            start.append('name', all[0].webkitRelativePath.split('/')[0]);
            const parent = form.querySelector('[name="import_parent"]')?.value;
            if (parent) {
                start.append('parent_id', parent);
            }
            const made = await post(form.dataset.start, start).catch(() => ({ ok: false, data: {} }));
            if (!made.ok) {
                status.textContent = made.data?.message ?? text('failed');
                input.disabled = false;
                bar.hidden = true;
                return;
            }

            let saved = 0;
            for (const [index, file] of files.entries()) {
                status.textContent = text('running', { done: index, total: files.length });
                const body = new FormData();
                body.append('file', file);
                body.append('path', file.webkitRelativePath.split('/').slice(1).join('/'));
                const result = await post(form.dataset.file.replace('__ID__', made.data.id), body).catch(() => ({ ok: false, status: 0 }));
                if (result.ok) {
                    saved++;
                } else if (result.status === 422) {
                    skipped++;
                } else {
                    status.textContent = text('failed');
                    input.disabled = false;
                    return;
                }
                bar.value = index + 1;
            }

            status.textContent = text('done', { saved }) + (skipped > 0 ? ' ' + text('skipped', { count: skipped }) : '');
            const link = form.querySelector('[data-import-open]');
            link.href = made.data.url;
            link.hidden = false;
            input.disabled = false;
            input.value = '';
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        setupServiceWorker();
        setupCamera(setupUploadQueue());
        setupPhoneActions();
        setupScanPhotos();
        setupPhonePrint();
        setupScanWait();
        setupPrintAndZoom();
        setupMenu();
        setupDropdowns();
        setupFilter();
        setupDialogs();
        setupColumns();
        setupScrollHint();
        setupBadges();
        setupTagSearch();
        setupColorField();
        setupFolderMenu();
        setupFolderImport();
        setupNotes();
        setupToasts();
        setupAjaxForms();
        setupPreviewPick();
    });
})();
