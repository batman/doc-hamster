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
        document.querySelectorAll('[data-dialog-open]').forEach(button => button.addEventListener('click', () => {
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
        }));
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
        cols.addEventListener('click', event => {
            const folder = event.target.closest('[data-fold]');
            if (folder) {
                const i = Number(folder.dataset.fold);
                set(i, false);
                strip(i).focus();
                return;
            }
            const opener = event.target.closest('[data-strip]');
            if (opener) {
                const i = Number(opener.dataset.strip);
                set(i, true);
                opened.push(i);
                pane(i).querySelector('[data-fold]')?.focus();
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
                    set(i, false);
                    strip(i).focus();
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
                notes.forEach(note => { note.style.left = ''; note.style.top = ''; });
                board.style.height = '';
                return;
            }
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

        wide.addEventListener('change', layout);
        addEventListener('resize', () => wide.matches && fit());
        layout();
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
            picker.value = radio.value;
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

    document.addEventListener('DOMContentLoaded', () => {
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
        setupNotes();
    });
})();
