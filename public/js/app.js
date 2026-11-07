// Side menu, receipt upload, dropdowns and the receipt filter (layouts/app).
// Loaded in <head> so the saved menu state applies before the first paint;
// the rest waits for the DOM.
(() => {
    const root = document.documentElement;
    const KEY = 'dh-nav';
    const read = () => { try { return localStorage.getItem(KEY); } catch { return null; } };
    const save = value => { try { localStorage.setItem(KEY, value); } catch { /* private mode: not remembered */ } };

    // A small event bus for modules (A74): DocHamster.on('window:open', root => …) runs for the first page and
    // for every window loaded later; 'window:close' before a window goes. Pages without windows count as one.
    const listeners = {};
    const DocHamster = window.DocHamster = {
        on(name, fn) {
            (listeners[name] ??= []).push(fn);
        },
        emit(name, root) {
            (listeners[name] ?? []).forEach(fn => fn(root));
        },
    };

    const saved = read();
    if (saved === 'closed' || saved === 'rail') {
        root.classList.add(`nav-${saved}`);
    }

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
        // The file is fetched ahead: the share menu has to open right on the click. Per window, also for later ones.
        const shared = new Map();
        DocHamster.on('window:open', root => root.querySelectorAll('[data-share]').forEach(button => {
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
        }));
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
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-copy]');
            if (button) {
                const field = document.getElementById(button.dataset.copy);
                field.select();
                navigator.clipboard?.writeText(field.value).then(() => { button.textContent = button.dataset.copied; });
            }
        });
        // Checklists save with every tick.
        document.addEventListener('change', event => event.target.closest('form[data-autosubmit]')?.submit());
        document.addEventListener('click', event => {
            const dialog = event.target.closest('[data-dialog]');
            if (dialog && (event.target === dialog || event.target.closest('[data-dialog-close]'))) {
                shut(dialog);
            }
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                document.querySelectorAll('[data-dialog]:not([hidden])').forEach(shut);
            }
        });
    };


    // ---------------------------------------------------------------------------------------------
    // Window system (Go 2026-11-07, points 22 and 24; docs/design/FRAMEWORK.md).
    // New windows open on the right, earlier ones collect on the left as strips (the history).
    // Full: the active window and the one it was opened from; all others are strips. A click on a
    // strip opens it again and only minimizes the ones right of it; the X removes a window. The
    // address follows the active window; the history survives a reload (per tab).
    // Modules only deliver <x-window> content and listen to DocHamster.on('window:open', root => …).
    // ---------------------------------------------------------------------------------------------
    const setupWindows = () => {
        const box = document.querySelector('[data-windows]');
        if (!box) {
            return;
        }
        const KEY = 'dh-windows';
        const wins = () => [...box.querySelectorAll(':scope > section[data-window]')];
        const byUrl = url => wins().find(win => win.dataset.windowUrl === url);
        const phone = matchMedia('(max-width: 63.99rem)');
        const smooth = change => (document.startViewTransition && !matchMedia('(prefers-reduced-motion: reduce)').matches
            ? document.startViewTransition(change).finished : Promise.resolve(change()));
        let seq = 0;
        const name = win => {
            win.dataset.windowId ??= `w${++seq}`;
            win.style.viewTransitionName = `win-${win.dataset.windowId}`;
        };

        const remember = () => {
            try {
                sessionStorage.setItem(KEY, JSON.stringify({
                    list: wins().map(win => ({ url: win.dataset.windowUrl, title: win.dataset.windowTitle, icon: win.querySelector('.win-strip .icon')?.outerHTML ?? '', opener: win.dataset.opener ?? null, closable: win.querySelector('[data-window-close]') !== null })),
                    active: box.querySelector(':scope > .is-active')?.dataset.windowUrl ?? null,
                }));
            } catch { /* not remembered */ }
        };

        const show = (active, push = true) => {
            const opener = active.dataset.opener ? byUrl(active.dataset.opener) : null;
            const prev = opener && wins().indexOf(opener) < wins().indexOf(active) ? opener : active.previousElementSibling;
            wins().forEach(win => {
                win.classList.toggle('is-active', win === active);
                win.classList.toggle('is-prev', win === prev && win !== active);
                win.classList.toggle('is-min', win !== active && win !== prev);
                win.querySelector('[data-window-back]')?.toggleAttribute('hidden', !(win === active && prev));
            });
            document.title = `${active.dataset.windowTitle} – ${document.title.split(' – ').pop()}`;
            if (push && location.href !== active.dataset.windowUrl) {
                history.pushState({ window: active.dataset.windowUrl }, '', active.dataset.windowUrl);
            }
            remember();
            document.dispatchEvent(new Event('dh:cols'));
        };

        const load = url => fetch(url, { credentials: 'same-origin', headers: { 'X-DocHamster-Window': '1', Accept: 'text/html' } })
            .then(response => (response.ok ? response.text() : Promise.reject(response)))
            .then(html => {
                const found = [...new DOMParser().parseFromString(html, 'text/html').querySelectorAll('section[data-window]')].pop();
                return found ? document.importNode(found, true) : Promise.reject(new Error('no window'));
            });

        // A strip from the stored history, filled when it is opened
        const placeholder = item => {
            const win = document.createElement('section');
            win.className = 'win is-min';
            win.dataset.window = '';
            win.dataset.windowUrl = item.url;
            win.dataset.windowTitle = item.title;
            win.dataset.lazy = '';
            if (item.opener) {
                win.dataset.opener = item.opener;
            }
            const strip = document.createElement('button');
            strip.type = 'button';
            strip.className = 'win-strip';
            strip.dataset.windowActivate = '';
            strip.innerHTML = `${item.icon}<span></span>`;
            strip.querySelector('span').textContent = item.title;
            win.append(strip);
            if (item.closable) {
                const x = document.querySelector('.win-strip-x')?.cloneNode(true);
                if (x) {
                    win.append(x);
                }
            }
            return win;
        };

        const activate = (win, push = true) => {
            if (win.dataset.lazy === undefined) {
                return smooth(() => show(win, push));
            }
            return load(win.dataset.windowUrl).then(full => {
                full.dataset.opener = win.dataset.opener ?? '';
                name(full);
                win.replaceWith(full);
                DocHamster.emit('window:open', full);
                return smooth(() => show(full, push));
            }).catch(() => { location.href = win.dataset.windowUrl; });
        };

        const open = (url, from) => {
            const known = byUrl(url);
            if (known) {
                return activate(known);
            }
            return load(url).then(win => {
                win.dataset.opener = from?.dataset.windowUrl ?? '';
                name(win);
                return smooth(() => {
                    box.append(win);
                    show(win);
                }).then(() => {
                    DocHamster.emit('window:open', win);
                    win.scrollIntoView({ inline: 'end', block: 'nearest' });
                });
            }).catch(() => { location.href = url; });
        };

        const close = win => {
            const wasActive = win.classList.contains('is-active');
            const next = (win.dataset.opener && byUrl(win.dataset.opener)) || win.previousElementSibling || win.nextElementSibling;
            DocHamster.emit('window:close', win);
            return smooth(() => {
                win.remove();
                if (wasActive && next) {
                    show(next, false);
                    history.replaceState({ window: next.dataset.windowUrl }, '', next.dataset.windowUrl);
                } else {
                    remember();
                }
            });
        };

        // Start: the windows from the server, plus the rest of this tab's history as strips (A62)
        wins().forEach(win => {
            name(win);
            if (win.previousElementSibling?.matches('section[data-window]')) {
                win.dataset.opener ??= win.previousElementSibling.dataset.windowUrl;
            }
        });
        try {
            const saved = JSON.parse(sessionStorage.getItem(KEY));
            const current = wins().at(-1)?.dataset.windowUrl;
            if (saved && saved.list.some(item => item.url === current)) {
                const order = saved.list.map(item => byUrl(item.url) ?? placeholder(item));
                wins().filter(win => !order.includes(win)).forEach(win => order.push(win));
                order.forEach(win => {
                    name(win);
                    box.append(win);
                });
            }
        } catch { /* fresh history */ }
        wins().filter(win => win.dataset.lazy === undefined).forEach(win => DocHamster.emit('window:open', win));
        show(wins().at(-1), false);
        history.replaceState({ window: wins().at(-1).dataset.windowUrl }, '', location.href);

        box.addEventListener('click', event => {
            const win = event.target.closest('section[data-window]');
            if (event.target.closest('[data-window-close]')) {
                event.preventDefault();
                close(win);
            } else if (event.target.closest('[data-window-activate]')) {
                activate(win);
            } else if (event.target.closest('[data-window-back]')) {
                const prev = box.querySelector(':scope > .is-prev');
                if (prev) {
                    activate(prev);
                }
            } else {
                const link = event.target.closest('a[data-window]');
                if (link && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) {
                    event.preventDefault();
                    open(link.href, win);
                }
            }
        });
        // A click into a minimized-looking previous window makes it the active one again
        addEventListener('popstate', event => {
            const win = byUrl(event.state?.window ?? location.href);
            if (win) {
                activate(win, false);
            } else {
                location.reload();
            }
        });
        phone.addEventListener('change', () => show(box.querySelector(':scope > .is-active') ?? wins().at(-1), false));
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

        // Several folders at once (PO, 2026-11-07): a tap or click on the handle without moving selects the folder,
        // more taps add folders of the same level; dragging a selected folder moves all selected ones together.
        const live = menu.querySelector('[data-folder-live]');
        const selected = () => [...menu.querySelectorAll('.nav-folder.is-selected')];
        const announce = () => {
            if (live) {
                const count = selected().length;
                live.textContent = count > 1 ? (menu.dataset.selectedText ?? '').replace(':count', String(count)) : '';
            }
        };
        const clearSelection = () => {
            selected().forEach(item => {
                item.classList.remove('is-selected');
                item.querySelector(':scope > .nav-folder-row > [data-grip]')?.setAttribute('aria-pressed', 'false');
            });
            announce();
        };
        const toggleSelection = item => {
            // Only one level at a time: the order is saved per level.
            if (selected().some(other => other.parentElement !== item.parentElement)) {
                clearSelection();
            }
            const on = item.classList.toggle('is-selected');
            item.querySelector(':scope > .nav-folder-row > [data-grip]')?.setAttribute('aria-pressed', on ? 'true' : 'false');
            announce();
        };
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                clearSelection();
            }
        });

        menu.querySelectorAll('[data-grip]').forEach(grip => {
            grip.setAttribute('aria-pressed', 'false');
            grip.addEventListener('pointerdown', event => {
                if (event.button !== 0) {
                    return;
                }
                event.preventDefault();
                const item = grip.closest('.nav-folder');
                const list = item.parentElement;
                const before = [...list.children].map(child => child.dataset.id).join();
                const startY = event.clientY;
                let dragging = false;
                let group = [item];

                const move = moveEvent => {
                    if (moveEvent.pointerId !== event.pointerId) {
                        return;
                    }
                    if (!dragging) {
                        // A few pixels of slack, so a tap selects instead of dragging.
                        if (Math.abs(moveEvent.clientY - startY) < 5) {
                            return;
                        }
                        dragging = true;
                        if (!item.classList.contains('is-selected')) {
                            clearSelection();
                        }
                        group = item.classList.contains('is-selected') ? [...list.children].filter(child => child.classList.contains('is-selected')) : [item];
                        // Listened on the document: moving the item in the DOM ends any pointer capture on the handle.
                        group.forEach(member => member.classList.add('is-dragging'));
                        document.body.classList.add('is-dragging-folder');
                    }
                    const others = [...list.children].filter(child => !group.includes(child));
                    const next = others.find(child => {
                        const box = child.querySelector('.nav-folder-row').getBoundingClientRect();
                        return moveEvent.clientY < box.top + box.height / 2;
                    }) ?? null;
                    if (group[group.length - 1].nextElementSibling !== next || group.some((member, index) => index > 0 && member.previousElementSibling !== group[index - 1])) {
                        group.forEach(member => list.insertBefore(member, next));
                    }
                };
                const end = endEvent => {
                    if (endEvent.pointerId !== event.pointerId) {
                        return;
                    }
                    document.removeEventListener('pointermove', move);
                    document.removeEventListener('pointerup', end);
                    document.removeEventListener('pointercancel', end);
                    if (!dragging) {
                        if (endEvent.type === 'pointerup') {
                            toggleSelection(item);
                        }
                        return;
                    }
                    group.forEach(member => member.classList.remove('is-dragging'));
                    document.body.classList.remove('is-dragging-folder');
                    if ([...list.children].map(child => child.dataset.id).join() !== before) {
                        save(list);
                    }
                };
                document.addEventListener('pointermove', move);
                document.addEventListener('pointerup', end);
                document.addEventListener('pointercancel', end);
            });
            // Keyboard: Enter or Space selects (the click has no pointer); arrow up/down moves the folder, or all selected ones, one place.
            grip.addEventListener('click', event => {
                if (event.detail === 0) {
                    toggleSelection(grip.closest('.nav-folder'));
                }
            });
            grip.addEventListener('keydown', event => {
                if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
                    return;
                }
                event.preventDefault();
                const item = grip.closest('.nav-folder');
                const list = item.parentElement;
                const group = item.classList.contains('is-selected') ? [...list.children].filter(child => child.classList.contains('is-selected')) : [item];
                if (event.key === 'ArrowUp') {
                    const above = group[0].previousElementSibling;
                    if (!above || group.includes(above)) {
                        return;
                    }
                    group.forEach(member => list.insertBefore(member, above));
                } else {
                    const below = group[group.length - 1].nextElementSibling;
                    if (!below || group.includes(below)) {
                        return;
                    }
                    const after = below.nextElementSibling;
                    group.forEach(member => list.insertBefore(member, after));
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
        setupMenu();
        setupDropdowns();
        setupFilter();
        setupDialogs();
        setupWindows();
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
        // Pages without the window system: the whole page is the one window
        if (!document.querySelector('[data-windows]')) {
            DocHamster.emit('window:open', document);
        }
    });
})();
