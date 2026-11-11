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
        // The active window says where an upload belongs (data-window-upload="folder=…", A79).
        const target = () => {
            form.querySelectorAll('[data-upload-target]').forEach(field => field.remove());
            const win = document.querySelector('[data-windows] > section.is-active') ?? [...document.querySelectorAll('section[data-window]')].pop();
            new URLSearchParams(win?.dataset.windowUpload ?? '').forEach((value, key) => {
                const field = document.createElement('input');
                field.type = 'hidden';
                field.name = key;
                field.value = value;
                field.dataset.uploadTarget = '';
                form.append(field);
            });
        };
        const send = () => {
            if (input.files.length === 0) {
                return;
            }
            target();
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

    // Dropdowns (filter, view, "⋯"): one listener for the whole page, so they work in every window, also in
    // windows loaded later and several side by side (Go 2026-11-07, point 26, A77). The icon button opens its
    // panel; a click outside, a click on an entry and Escape close it.
    const setupDropdowns = () => {
        const setOpen = (dropdown, open) => {
            dropdown.querySelector(':scope > .dropdown-panel').hidden = !open;
            dropdown.querySelector(':scope > .icon-button').setAttribute('aria-expanded', String(open));
        };
        const openOnes = () => [...document.querySelectorAll('.dropdown')].filter(d => d.querySelector(':scope > .dropdown-panel')?.hidden === false);
        document.addEventListener('click', event => {
            const toggle = event.target.closest('.dropdown > .icon-button');
            const inside = event.target.closest('.dropdown');
            openOnes().filter(d => d !== inside && !d.contains(inside)).forEach(d => setOpen(d, false));
            if (toggle) {
                const dropdown = toggle.parentElement;
                setOpen(dropdown, dropdown.querySelector(':scope > .dropdown-panel').hidden);
            } else if (inside && event.target.closest('.dropdown-panel a, .dropdown-panel .menu-item')) {
                setOpen(inside, false);
            }
        });
        document.addEventListener('keydown', event => {
            const open = openOnes().pop();
            if (event.key === 'Escape' && open) {
                setOpen(open, false);
                open.querySelector(':scope > .icon-button').focus();
            }
        });
    };

    // Receipt filter: ticks collect, "Anwenden" filters. A receipt must have all ticked tags. Each filter works
    // on the list of its own window. When the window doesn't hold every receipt, the server filters instead.
    const setupFilter = () => {
        const apply = filter => {
            if (filter.dataset.complete !== '1') {
                filter.submit();
                return;
            }
            const scope = filter.closest('section[data-window]') ?? document;
            const wanted = [...filter.querySelectorAll('input[name="tags[]"]')].filter(box => box.checked).map(box => box.value);
            let shown = 0;
            scope.querySelectorAll('[data-item]').forEach(item => {
                const has = item.dataset.tags.split(' ');
                item.hidden = !wanted.every(tag => has.includes(tag));
                shown += item.hidden ? 0 : 1;
            });
            scope.querySelectorAll('[data-group]').forEach(group => {
                group.hidden = group.querySelector('[data-item]:not([hidden])') === null;
            });
            const none = scope.querySelector('[data-no-match]');
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

            // Keep the window's address in step, so reloading or a shared link shows the same.
            const url = new URL(scope.dataset?.windowUrl ?? location.href, location.href);
            url.searchParams.delete('tags[]');
            wanted.forEach(tag => url.searchParams.append('tags[]', tag));
            if (scope !== document) {
                scope.dataset.windowUrl = url.href;
            }
            if (scope === document || scope.classList.contains('is-active')) {
                history.replaceState({ window: url.href }, '', url);
            }
        };
        const close = filter => {
            filter.querySelector('.dropdown-panel').hidden = true;
            filter.querySelector('.icon-button').setAttribute('aria-expanded', 'false');
        };
        document.addEventListener('submit', event => {
            const filter = event.target.closest('.dropdown[data-kind="filter"]');
            if (filter && filter.dataset.complete === '1') {
                event.preventDefault();
                apply(filter);
                close(filter);
            }
        });
        document.addEventListener('click', event => {
            const reset = event.target.closest('.dropdown[data-kind="filter"] [data-reset]');
            if (reset) {
                const filter = reset.closest('.dropdown');
                filter.querySelectorAll('input[name="tags[]"]').forEach(box => { box.checked = false; });
                apply(filter);
                close(filter);
            }
        });
    };

    // Window heads on phones (point 30, A80): the buttons of the head go into its "⋯" menu, the title keeps its room.
    const setupHeadActions = () => {
        const phone = matchMedia('(max-width: 63.99rem)');
        const place = root => root.querySelectorAll('[data-win-actions]').forEach(actions => {
            const panel = actions.parentElement.querySelector('[data-win-more] .dropdown-panel');
            const [from, to] = phone.matches ? [actions, panel] : [panel, actions];
            [...from.childNodes].forEach(node => to.append(node));
        });
        DocHamster.on('window:open', place);
        phone.addEventListener('change', () => place(document));
    };

    // Sortable list (point 28, A78, A92): a row of a [data-sortable] list is picked up as a whole – with the mouse
    // after a few pixels, with a finger after a short hold – and hangs on the pointer. A dashed placeholder keeps
    // its place, an orange bar shows where it lands; near the edge the list scrolls along. On release it glides to
    // the bar, Escape puts it back. Keyboard on the handle: Space picks up, arrow keys move, Space drops.
    // Afterwards DocHamster.emit('list:sorted', { list, ids }) – the module saves.
    const setupSortable = () => {
        const rows = list => [...list.querySelectorAll(':scope > [data-id]')];
        const done = list => DocHamster.emit('list:sorted', { list, ids: rows(list).map(row => row.dataset.id) });
        const still = window.matchMedia('(prefers-reduced-motion: reduce)');
        const scroller = el => {
            for (let node = el.parentElement; node && node !== document.body; node = node.parentElement) {
                const style = getComputedStyle(node);
                if (/(auto|scroll)/.test(style.overflowY) && node.scrollHeight > node.clientHeight) {
                    return node;
                }
            }
            return document.scrollingElement;
        };
        // A short glide from where the row was drawn to where it now sits.
        const glide = (row, from) => {
            if (still.matches) {
                return;
            }
            const to = row.getBoundingClientRect();
            row.style.transform = `translate(${from.left - to.left}px, ${from.top - to.top}px)`;
            row.getBoundingClientRect();
            row.classList.add('is-gliding');
            row.style.transform = '';
            row.addEventListener('transitionend', () => row.classList.remove('is-gliding'), { once: true });
        };

        const drag = (row, start) => {
            const list = row.parentElement;
            const order = () => rows(list).map(r => r.dataset.id).join();
            const before = order();
            const box = row.getBoundingClientRect();
            const offset = { x: start.clientX - box.left, y: start.clientY - box.top };
            const area = scroller(list);
            const placeholder = document.createElement('li');
            placeholder.className = 'pick-placeholder';
            placeholder.style.height = `${box.height}px`;
            const bar = document.createElement('div');
            bar.className = 'pick-drop';
            bar.hidden = true;
            list.insertBefore(placeholder, row);
            list.append(bar);
            Object.assign(row.style, { width: `${box.width}px`, left: `${box.left}px`, top: `${box.top}px` });
            row.classList.add('is-lifted');
            document.documentElement.classList.add('is-sorting');
            // target: the row to land before; null = at the end; placeholder = stays where it was.
            let target = placeholder;
            let pointer = { x: start.clientX, y: start.clientY };
            let frame = 0;

            const aim = () => {
                row.style.left = `${pointer.x - offset.x}px`;
                row.style.top = `${pointer.y - offset.y}px`;
                const others = rows(list).filter(r => r !== row);
                const next = others.find(r => pointer.y < r.getBoundingClientRect().top + r.offsetHeight / 2) ?? null;
                const after = others.filter(r => placeholder.compareDocumentPosition(r) & Node.DOCUMENT_POSITION_FOLLOWING)[0] ?? null;
                const home = next === after;
                target = home ? placeholder : next;
                bar.hidden = home;
                if (!home) {
                    const listTop = list.getBoundingClientRect().top;
                    const gap = parseFloat(getComputedStyle(list).rowGap) || 0;
                    const y = next ? next.getBoundingClientRect().top - gap / 2 : others.at(-1).getBoundingClientRect().bottom + gap / 2;
                    bar.style.top = `${y - listTop}px`;
                }
            };
            const edge = () => {
                const rect = area === document.scrollingElement ? { top: 0, bottom: window.innerHeight } : area.getBoundingClientRect();
                const zone = 48;
                const speed = pointer.y < rect.top + zone ? pointer.y - (rect.top + zone) : pointer.y > rect.bottom - zone ? pointer.y - (rect.bottom - zone) : 0;
                if (speed) {
                    area.scrollTop += speed / 3;
                    aim();
                }
                frame = requestAnimationFrame(edge);
            };
            const move = event => {
                pointer = { x: event.clientX, y: event.clientY };
                aim();
            };
            const finish = keep => {
                cancelAnimationFrame(frame);
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                window.removeEventListener('pointercancel', cancel);
                window.removeEventListener('keydown', key, true);
                sorting = false;
                document.documentElement.classList.remove('is-sorting');
                const from = row.getBoundingClientRect();
                row.classList.remove('is-lifted');
                row.style.width = row.style.left = row.style.top = '';
                list.insertBefore(row, keep && target !== placeholder ? target : placeholder);
                placeholder.remove();
                bar.remove();
                glide(row, from);
                if (order() !== before) {
                    done(list);
                }
                // The release must not also open the row's link.
                const swallow = event => { event.preventDefault(); event.stopPropagation(); };
                window.addEventListener('click', swallow, { capture: true, once: true });
                setTimeout(() => window.removeEventListener('click', swallow, true), 0);
            };
            const up = () => finish(true);
            const cancel = () => finish(false);
            const key = event => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    finish(false);
                }
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
            window.addEventListener('pointercancel', cancel);
            window.addEventListener('keydown', key, true);
            sorting = true;
            aim();
            frame = requestAnimationFrame(edge);
        };

        // Registered up front and not passive, so a finger that picked a row up moves it instead of the page.
        let sorting = false;
        document.addEventListener('touchmove', event => {
            if (sorting) {
                event.preventDefault();
            }
        }, { passive: false });
        document.addEventListener('dragstart', event => {
            if (event.target.closest?.('[data-sortable] > [data-id]')) {
                event.preventDefault();
            }
        });
        document.addEventListener('pointerdown', event => {
            const row = event.target.closest('[data-sortable] > [data-id]');
            if (!row || event.button !== 0 || event.target.closest('input, select, textarea, .dropdown, form, button:not(.pick-handle)')) {
                return;
            }
            const touch = event.pointerType === 'touch';
            const onHandle = !!event.target.closest('.pick-handle');
            const start = { clientX: event.clientX, clientY: event.clientY };
            if (!touch) {
                event.preventDefault(); // no text selection while dragging
            }
            let timer = 0;
            const watch = e => {
                const far = Math.hypot(e.clientX - start.clientX, e.clientY - start.clientY);
                if (touch && !onHandle) {
                    if (far > 8) {
                        stop(); // the finger scrolls
                    }
                } else if (far > 4) {
                    begin();
                }
            };
            const stop = () => {
                clearTimeout(timer);
                window.removeEventListener('pointermove', watch);
                window.removeEventListener('pointerup', stop);
                window.removeEventListener('pointercancel', stop);
            };
            const begin = () => {
                stop();
                drag(row, start);
            };
            window.addEventListener('pointermove', watch);
            window.addEventListener('pointerup', stop);
            window.addEventListener('pointercancel', stop);
            if (touch && !onHandle) {
                timer = setTimeout(begin, 350);
            }
        });
        let carried = null;
        document.addEventListener('keydown', event => {
            const handle = event.target.closest('[data-sortable] .pick-handle');
            if (!handle) {
                return;
            }
            const row = handle.closest('[data-id]');
            const list = row.parentElement;
            if (event.key === ' ' || event.key === 'Enter') {
                event.preventDefault();
                if (carried) {
                    carried.row.classList.remove('is-dragging');
                    if (rows(list).map(r => r.dataset.id).join() !== carried.before) {
                        done(list);
                    }
                    carried = null;
                } else {
                    carried = { row, before: rows(list).map(r => r.dataset.id).join(), next: row.nextElementSibling };
                    row.classList.add('is-dragging');
                }
            } else if (carried && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
                event.preventDefault();
                const sibling = event.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling?.nextElementSibling ?? null;
                if (event.key === 'ArrowUp' && !sibling) {
                    return;
                }
                list.insertBefore(row, sibling);
                handle.focus();
            } else if (carried && event.key === 'Escape') {
                list.insertBefore(row, carried.next);
                row.classList.remove('is-dragging');
                carried = null;
                handle.focus();
            }
        });
    };

    // Tabs (A93): arrow keys, Home and End move between the tabs of a bar.
    document.addEventListener('keydown', event => {
        const tab = event.target.closest?.('.tab-bar .tab');
        if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            return;
        }
        const tabs = [...tab.parentElement.querySelectorAll('.tab')];
        const at = tabs.indexOf(tab);
        const to = { ArrowLeft: at - 1, ArrowRight: at + 1, Home: 0, End: tabs.length - 1 }[event.key];
        event.preventDefault();
        tabs[(to + tabs.length) % tabs.length].focus();
    });

    // Tooltips (A103): one layer on <body> above everything, so cards, columns and scrolling windows can't cut
    // them off. The element's ::after rules still decide whether its tooltip shows at all; the side comes
    // from .tooltip-right (else below, or above when there is no room). Mouse and keyboard only, not touch.
    const setupTips = () => {
        const tip = document.createElement('div');
        tip.className = 'tip';
        tip.setAttribute('role', 'tooltip');
        tip.hidden = true;
        document.body.append(tip);
        root.classList.add('has-tips');
        let owner = null;
        let timer = 0;
        const hide = () => {
            clearTimeout(timer);
            owner = null;
            tip.classList.remove('is-shown');
            tip.hidden = true;
        };
        const place = el => {
            const box = el.getBoundingClientRect();
            const right = el.classList.contains('tooltip-right');
            tip.hidden = false;
            const w = tip.offsetWidth;
            const h = tip.offsetHeight;
            let x;
            let y;
            let side;
            if (right && box.right + 10 + w < innerWidth) {
                side = 'right';
                x = box.right + 10;
                y = box.top + box.height / 2 - h / 2;
            } else {
                side = box.bottom + 10 + h > innerHeight ? 'above' : 'below';
                x = Math.min(Math.max(8, box.left + box.width / 2 - w / 2), innerWidth - w - 8);
                y = side === 'below' ? box.bottom + 10 : box.top - 10 - h;
                tip.style.setProperty('--tip-arrow', `${box.left + box.width / 2 - x}px`);
            }
            tip.dataset.side = side;
            tip.style.left = `${Math.round(x)}px`;
            tip.style.top = `${Math.round(y)}px`;
        };
        const show = el => {
            if (el === owner) {
                return;
            }
            hide();
            if (!el.dataset.tooltip || getComputedStyle(el, '::after').display === 'none') {
                return;
            }
            owner = el;
            timer = setTimeout(() => {
                if (owner !== el || !el.isConnected) {
                    return;
                }
                tip.textContent = el.dataset.tooltip;
                place(el);
                tip.classList.add('is-shown');
            }, 300);
        };
        document.addEventListener('pointerover', event => {
            if (event.pointerType === 'touch') {
                return;
            }
            const el = event.target.closest?.('[data-tooltip]');
            if (el) {
                show(el);
            } else if (owner) {
                hide();
            }
        });
        document.addEventListener('pointerout', event => {
            if (owner && !owner.contains(event.relatedTarget)) {
                hide();
            }
        });
        document.addEventListener('focusin', event => {
            const el = event.target.closest?.('[data-tooltip]');
            if (el?.matches(':focus-visible')) {
                show(el);
            }
        });
        document.addEventListener('focusout', hide);
        document.addEventListener('pointerdown', hide, true);
        document.addEventListener('scroll', hide, true);
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                hide();
            }
        });
        // A label that changes while shown (the pin) updates the tooltip.
        new MutationObserver(() => {
            if (owner && !tip.hidden) {
                tip.textContent = owner.dataset.tooltip;
                place(owner);
            }
        }).observe(document.body, { subtree: true, attributeFilter: ['data-tooltip'] });
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
    // Window system (points 22, 24, 40, A96; docs/design/FRAMEWORK.md).
    // Every window is an area of its own: it scrolls by itself and closes by itself. A link with
    // data-window in a window closes all windows right of that window, makes that window narrow (a strip
    // in the history on the left) and opens the new one on the right. A strip opened again just becomes
    // wide – nothing else changes; the wide windows share the width (each at least its minimum width,
    // data-window-min), and when that doesn't fit the row scrolls sideways. A window made wide again from its
    // strip is pinned (A97, A99): opening something from it leaves it wide; the pin in its head shows and
    // switches that. The address follows the
    // active window (the last one opened); the history survives a reload (per tab).
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
        const smooth = change => {
            if (!document.startViewTransition || matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return Promise.resolve(change());
            }
            const transition = document.startViewTransition(change);
            transition.ready.catch(() => { /* skipped: the change still happened */ });
            return transition.finished;
        };
        let seq = 0;
        const name = win => {
            win.dataset.windowId ??= `w${++seq}`;
            win.style.viewTransitionName = `win-${win.dataset.windowId}`;
            if (win.dataset.windowMin) {
                win.style.setProperty('--win-min', win.dataset.windowMin);
            }
        };
        const active = () => box.querySelector(':scope > .is-active');
        // Pinned windows stay wide when something opens from them (A97, A99).
        const pin = (win, on) => {
            win.classList.toggle('is-pinned', on);
            const button = win.querySelector('[data-window-pin]');
            if (button) {
                const label = on ? button.dataset.labelUnpin : button.dataset.labelPin;
                button.setAttribute('aria-pressed', String(on));
                button.setAttribute('aria-label', label);
                button.dataset.tooltip = label;
            }
        };
        const reveal = win => requestAnimationFrame(() => win.scrollIntoView({ inline: 'nearest', block: 'nearest', behavior: 'smooth' }));

        // The fold arrow shows only while another window is wide too – one window always stays wide (A102).
        const marks = () => {
            const wide = wins().filter(win => !win.classList.contains('is-min'));
            wins().forEach(win => win.querySelector('[data-window-fold]')?.toggleAttribute('hidden', wide.length < 2 || win.classList.contains('is-min')));
        };
        const remember = () => {
            marks();
            try {
                sessionStorage.setItem(KEY, JSON.stringify({
                    list: wins().map(win => ({ url: win.dataset.windowUrl, title: win.dataset.windowTitle, icon: win.querySelector('.win-strip .strip-label > .icon')?.outerHTML ?? '', min: win.classList.contains('is-min'), pinned: win.classList.contains('is-pinned'), closable: win.querySelector('[data-window-close]') !== null })),
                    active: active()?.dataset.windowUrl ?? null,
                }));
            } catch { /* not remembered */ }
        };

        // The active window: its address, its title, and on phones the only one shown (with a back arrow).
        const focus = (win, push = true) => {
            wins().forEach(other => {
                other.classList.toggle('is-active', other === win);
                other.querySelector('[data-window-back]')?.toggleAttribute('hidden', !(other === win && other.previousElementSibling?.matches('section[data-window]')));
            });
            document.title = `${win.dataset.windowTitle} – ${document.title.split(' – ').pop()}`;
            if (push && location.href !== win.dataset.windowUrl) {
                history.pushState({ window: win.dataset.windowUrl }, '', win.dataset.windowUrl);
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
            win.classList.toggle('is-pinned', !!item.pinned);
            // The same strip as a server window has: arrow, icon and title, pin mark.
            const strip = document.querySelector('.win-strip')?.cloneNode(true) ?? document.createElement('button');
            const label = strip.querySelector('.strip-label');
            if (label) {
                label.innerHTML = `${item.icon}<span></span>`;
                label.querySelector('span').textContent = item.title;
            }
            strip.setAttribute('aria-label', item.title);
            win.append(strip);
            if (item.closable) {
                const x = document.querySelector('.win-strip-x')?.cloneNode(true);
                if (x) {
                    win.append(x);
                }
            }
            return win;
        };
        // A stored strip gets its content (once) when it is shown wide.
        const fill = win => (win.dataset.lazy === undefined ? Promise.resolve(win) : load(win.dataset.windowUrl).then(full => {
            name(full);
            full.classList.toggle('is-min', win.classList.contains('is-min'));
            full.classList.toggle('is-active', win.classList.contains('is-active'));
            pin(full, win.classList.contains('is-pinned'));
            win.replaceWith(full);
            DocHamster.emit('window:open', full);
            return full;
        }));

        // A strip becomes wide again; the other windows stay as they are (point 40.6).
        const restore = (win, push = true) => fill(win).then(full => smooth(() => {
            if (full.classList.contains('is-min')) {
                pin(full, true);
            }
            full.classList.remove('is-min');
            focus(full, push);
        }).then(() => reveal(full))).catch(() => { location.href = win.dataset.windowUrl; });

        // A link in a window: everything right of that window closes, the window itself becomes a strip,
        // the new one opens on the right (points 40.2, 40.3). A window already in the history just opens again.
        const open = (url, from) => {
            const known = byUrl(url);
            if (known) {
                return restore(known);
            }
            return load(url).then(win => {
                name(win);
                return smooth(() => {
                    if (from) {
                        let next = from.nextElementSibling;
                        while (next?.matches('section[data-window]')) {
                            const gone = next;
                            next = next.nextElementSibling;
                            DocHamster.emit('window:close', gone);
                            gone.remove();
                        }
                        from.classList.toggle('is-min', !from.classList.contains('is-pinned'));
                    }
                    box.append(win);
                    focus(win);
                }).then(() => {
                    DocHamster.emit('window:open', win);
                    reveal(win);
                });
            }).catch(() => { location.href = url; });
        };

        // The fold arrow: the window becomes a strip and loses its pin; the others share the room (A102).
        const fold = win => smooth(() => {
            win.classList.add('is-min');
            pin(win, false);
            if (win.classList.contains('is-active')) {
                const all = wins();
                const at = all.indexOf(win);
                const next = all.filter(other => !other.classList.contains('is-min'))
                    .sort((a, b) => Math.abs(all.indexOf(a) - at) - Math.abs(all.indexOf(b) - at))[0];
                if (next) {
                    focus(next, false);
                    history.replaceState({ window: next.dataset.windowUrl }, '', next.dataset.windowUrl);
                    return;
                }
            }
            remember();
        });

        // The X: only this window goes. If it was the active one, the nearest wide window takes over;
        // if no wide window is left, the nearest strip opens.
        const close = win => {
            const wasActive = win.classList.contains('is-active');
            const all = wins();
            const at = all.indexOf(win);
            const rest = all.filter(other => other !== win);
            const nearest = list => [...list].sort((a, b) => Math.abs(all.indexOf(a) - at) - Math.abs(all.indexOf(b) - at) || all.indexOf(a) - all.indexOf(b))[0];
            const wide = nearest(rest.filter(other => !other.classList.contains('is-min')));
            const next = wide ?? nearest(rest);
            DocHamster.emit('window:close', win);
            return smooth(() => {
                win.remove();
                if (wasActive && wide) {
                    focus(wide, false);
                    history.replaceState({ window: wide.dataset.windowUrl }, '', wide.dataset.windowUrl);
                } else {
                    remember();
                }
            }).then(() => {
                if (!wide && next) {
                    restore(next, false).then(() => history.replaceState({ window: next.dataset.windowUrl }, '', next.dataset.windowUrl));
                }
            });
        };

        // Start: the windows from the server, plus the rest of this tab's history (A62). Without a stored
        // history only the last window is wide – the ones before it are where it was opened from.
        wins().forEach(name);
        let saved = null;
        try {
            saved = JSON.parse(sessionStorage.getItem(KEY));
        } catch { /* fresh history */ }
        const current = wins().at(-1);
        if (saved && saved.list.some(item => item.url === current.dataset.windowUrl)) {
            const order = saved.list.map(item => {
                const win = byUrl(item.url) ?? placeholder(item);
                win.classList.toggle('is-min', item.min && win !== current);
                pin(win, !!item.pinned);
                return win;
            });
            wins().filter(win => !order.includes(win)).forEach(win => order.push(win));
            order.forEach(win => {
                name(win);
                box.append(win);
            });
        } else {
            wins().forEach(win => win.classList.toggle('is-min', win !== current));
        }
        wins().filter(win => win.dataset.lazy === undefined).forEach(win => DocHamster.emit('window:open', win));
        focus(current, false);
        history.replaceState({ window: current.dataset.windowUrl }, '', location.href);
        // Stored windows that were wide get their content now.
        wins().filter(win => win.dataset.lazy !== undefined && !win.classList.contains('is-min')).forEach(fill);
        reveal(current);

        box.addEventListener('click', event => {
            const win = event.target.closest('section[data-window]');
            if (event.target.closest('[data-window-fold]')) {
                fold(win);
            } else if (event.target.closest('[data-window-pin]')) {
                pin(win, !win.classList.contains('is-pinned'));
                remember();
            } else if (event.target.closest('[data-window-close]')) {
                event.preventDefault();
                close(win);
            } else if (event.target.closest('[data-window-activate]')) {
                restore(win);
            } else if (event.target.closest('[data-window-back]')) {
                const before = win.previousElementSibling;
                if (before?.matches('section[data-window]')) {
                    restore(before);
                }
            } else {
                const link = event.target.closest('a[data-window]');
                if (link && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) {
                    event.preventDefault();
                    open(link.href, win);
                }
            }
        });
        // A click into a wide window that isn't the active one makes it the active one (address, title).
        box.addEventListener('pointerdown', event => {
            const win = event.target.closest('section[data-window]');
            if (win && !win.classList.contains('is-min') && !win.classList.contains('is-active') && !event.target.closest('a[data-window], [data-window-close]')) {
                focus(win);
            }
        });
        addEventListener('popstate', event => {
            const win = byUrl(event.state?.window ?? location.href);
            if (win) {
                restore(win, false);
            } else {
                location.reload();
            }
        });
        phone.addEventListener('change', () => focus(active() ?? wins().at(-1), false));
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

    // Notes board (Home, Go of 2026-11-08): columns like Trello. A note is dragged by its head between and within
    // the columns, a column by its head; the order is saved. A column folds to a strip (remembered in this browser).
    const setupNotes = () => {
        const board = document.querySelector('[data-notes]');
        if (!board) {
            return;
        }
        const notes = [...board.querySelectorAll('.note')];
        notes.forEach(note => {
            if (note.dataset.opacity) {
                note.style.setProperty('--note-alpha', `${note.dataset.opacity}%`);
            }
            if (note.dataset.h) {
                note.style.height = `${note.dataset.h}px`;
            }
        });
        // Edit dialog of a locked note: unticking "Gesperrt" frees the fields right away.
        document.querySelectorAll('[data-note-lock]').forEach(box => {
            const fields = box.form.querySelector('[data-note-fields]');
            const locked = fields.disabled;
            box.addEventListener('change', () => { fields.disabled = locked && box.checked; });
        });

        const send = (url, fields) => {
            const body = new FormData();
            body.append('_token', board.dataset.csrf);
            Object.entries(fields).forEach(([key, value]) => (Array.isArray(value)
                ? value.forEach(item => body.append(`${key}[]`, item))
                : body.append(key, value)));
            return fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        };
        const failed = () => location.reload();

        // "+ Notiz" in a column puts the new note into that column (A33).
        document.querySelectorAll('[data-note-column]').forEach(button => button.addEventListener('click', () => {
            const field = document.querySelector('[data-note-new-column]');
            if (field) {
                field.value = button.dataset.noteColumn;
            }
        }));

        // Height: dragged at the bottom-right corner (the width is the column's, A34); saved a moment after the last change.
        const timers = new Map();
        const sized = new ResizeObserver(entries => entries.forEach(({ target: note }) => {
            const h = String(Math.round(note.offsetHeight));
            if (!note.style.height || h === note.dataset.h) {
                return;
            }
            clearTimeout(timers.get(note));
            timers.set(note, setTimeout(() => {
                note.dataset.h = h;
                send(note.dataset.noteLook, { h }).catch(() => { /* offline: kept until the next reload */ });
            }, 500));
        }));
        notes.filter(note => note.dataset.noteLook).forEach(note => sized.observe(note));

        // Folding (A39): the column becomes a strip with its name; this browser remembers it.
        const FOLDED = 'notes.folded';
        let folded = [];
        try { folded = JSON.parse(localStorage.getItem(FOLDED) || '[]'); } catch { /* not remembered */ }
        const fold = (column, on) => {
            column.classList.toggle('is-folded', on);
            column.querySelector('.kanban-body').hidden = on;
            column.querySelector('[data-kanban-unfold]').hidden = !on;
            folded = folded.filter(id => id !== column.dataset.columnId).concat(on ? [column.dataset.columnId] : []);
            try { localStorage.setItem(FOLDED, JSON.stringify(folded)); } catch { /* not remembered */ }
        };
        board.querySelectorAll('.kanban-col[data-column-id]').forEach(column => {
            if (folded.includes(column.dataset.columnId)) {
                fold(column, true);
            }
        });
        board.addEventListener('click', event => {
            const folder = event.target.closest('[data-kanban-fold]');
            const opener = event.target.closest('[data-kanban-unfold]');
            if (folder || opener) {
                const column = (folder || opener).closest('.kanban-col');
                fold(column, Boolean(folder));
                column.querySelector(folder ? '[data-kanban-unfold]' : '[data-kanban-fold]')?.focus();
            }
        });

        // Renaming: click on the name and type, like Trello. Enter or leaving saves, Esc goes back.
        board.querySelectorAll('[data-kanban-rename]').forEach(input => {
            let saved = input.value;
            input.addEventListener('keydown', event => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    input.blur();
                } else if (event.key === 'Escape') {
                    input.value = saved;
                    input.blur();
                }
            });
            input.addEventListener('change', () => {
                const name = input.value.trim();
                if (name === '') {
                    input.value = saved;
                    return;
                }
                send(input.dataset.kanbanRename, { name }).then(response => {
                    if (!response.ok) {
                        failed();
                    }
                    saved = name;
                    const column = input.closest('.kanban-col');
                    column.setAttribute('aria-label', name);
                    column.querySelector('.strip-label > span').textContent = name;
                }).catch(failed);
            });
        });

        // Drag & drop (A32, 73): a gap shows where the note or column lands.
        const counts = () => board.querySelectorAll('.kanban-col[data-column-id]').forEach(column => {
            const n = String(column.querySelectorAll('.kanban-cards > .note').length);
            column.querySelectorAll('.kanban-count').forEach(count => { count.textContent = n; });
        });
        const noteGap = document.createElement('li');
        noteGap.className = 'kanban-gap';
        const columnGap = document.createElement('div');
        columnGap.className = 'kanban-col-gap';
        let dragged = null;
        board.addEventListener('pointerdown', event => {
            if (event.target.closest('button, a, input, textarea, form')) {
                return;
            }
            const note = event.target.closest('.note-head')?.closest('.note[data-note-move]');
            const head = event.target.closest('[data-kanban-grip]');
            if (note) {
                note.draggable = true;
            } else if (head) {
                head.draggable = true;
            }
        });
        board.addEventListener('dragstart', event => {
            const target = event.target;
            if (!(target instanceof HTMLElement) || target.draggable !== true) {
                return;
            }
            dragged = target.matches('.note') ? target : target.closest('.kanban-col');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', '');
            const gap = dragged.matches('.note') ? noteGap : columnGap;
            gap.style.height = dragged.matches('.note') ? `${dragged.offsetHeight}px` : '';
            gap.style.width = dragged.matches('.note') ? '' : `${dragged.offsetWidth}px`;
            // Hidden only after the browser took its picture of it.
            requestAnimationFrame(() => {
                dragged?.after(gap);
                dragged?.classList.add('is-dragging');
            });
        });
        board.addEventListener('dragover', event => {
            if (!dragged) {
                return;
            }
            event.preventDefault();
            if (dragged.matches('.note')) {
                const list = event.target.closest('.kanban-col')?.querySelector('.kanban-cards');
                if (!list || list.closest('.kanban-col').classList.contains('is-folded')) {
                    return;
                }
                const before = [...list.querySelectorAll(':scope > .note:not(.is-dragging)')]
                    .find(card => { const r = card.getBoundingClientRect(); return event.clientY < r.top + r.height / 2; });
                if (before ? noteGap.nextElementSibling !== before : list.lastElementChild !== noteGap) {
                    list.insertBefore(noteGap, before ?? null);
                }
            } else {
                const before = [...board.querySelectorAll(':scope > .kanban-col[data-column-id]:not(.is-dragging)')]
                    .find(column => { const r = column.getBoundingClientRect(); return event.clientX < r.left + r.width / 2; });
                const end = board.querySelector(':scope > .kanban-new');
                board.insertBefore(columnGap, before ?? end ?? null);
            }
        });
        board.addEventListener('drop', event => {
            if (!dragged) {
                return;
            }
            event.preventDefault();
            if (dragged.matches('.note') && noteGap.parentElement) {
                const list = noteGap.parentElement;
                list.insertBefore(dragged, noteGap);
                counts();
                send(dragged.dataset.noteMove, {
                    column: list.dataset.kanbanCards,
                    order: [...list.querySelectorAll(':scope > .note')].map(card => card.dataset.noteId),
                }).then(response => { if (!response.ok) { failed(); } }).catch(failed);
            } else if (columnGap.parentElement) {
                board.insertBefore(dragged, columnGap);
                send(board.dataset.columnsOrder, {
                    order: [...board.querySelectorAll(':scope > .kanban-col[data-column-id]')].map(column => column.dataset.columnId),
                }).then(response => { if (!response.ok) { failed(); } }).catch(failed);
            }
        });
        board.addEventListener('dragend', () => {
            noteGap.remove();
            columnGap.remove();
            dragged?.classList.remove('is-dragging');
            board.querySelectorAll('[draggable="true"]').forEach(el => { el.draggable = false; });
            dragged = null;
        });
        document.addEventListener('pointerup', () => {
            if (!dragged) {
                board.querySelectorAll('[draggable="true"]').forEach(el => { el.draggable = false; });
            }
        });


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
    // Colour field (tags, folders): set up in every window, also in windows loaded later (window system).
    const setupColorField = root => root.querySelectorAll('[data-color-field]:not([data-ready])').forEach(field => {
        field.dataset.ready = '';
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
    });
    DocHamster.on('window:open', setupColorField);

    // Folder order (PO, 2026-11-07): the folder rows in a Belege window are a sortable list of the framework;
    // after a move the new order of that level is saved right away. "trash" stands for the Papierkorb.
    DocHamster.on('list:sorted', ({ list, ids }) => {
        const url = list.dataset.folderOrder;
        if (!url) {
            return;
        }
        const body = new FormData();
        ids.forEach(id => body.append('order[]', id));
        if (list.dataset.parent) {
            body.append('parent_id', list.dataset.parent);
        }
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        fetch(url, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, credentials: 'same-origin' })
            .then(response => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }
            })
            .catch(() => {
                // The framework's error line above the list (no JS toast yet).
                if (!list.previousElementSibling?.matches('[data-order-error]')) {
                    const error = document.createElement('p');
                    error.className = 'error';
                    error.setAttribute('role', 'alert');
                    error.dataset.orderError = '';
                    error.textContent = list.dataset.orderFailed;
                    list.before(error);
                }
            });
    });

    // The import form sits in a window of the Ordner page, which may be loaded later (window system).
    const setupFolderImport = root => root.querySelectorAll('[data-folder-import]:not([data-ready])').forEach(form => {
        form.dataset.ready = '';
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
    });
    DocHamster.on('window:open', setupFolderImport);

    document.addEventListener('DOMContentLoaded', () => {
        setupMenu();
        setupDropdowns();
        setupFilter();
        setupSortable();
        setupTips();
        // The active tab stays in view when the tab bar scrolls sideways (A93).
        document.querySelectorAll('.tab-bar [aria-current="page"]').forEach(tab => tab.scrollIntoView({ block: 'nearest', inline: 'nearest' }));
        setupHeadActions();
        setupDialogs();
        setupWindows();
        setupColumns();
        setupScrollHint();
        setupBadges();
        setupTagSearch();
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
