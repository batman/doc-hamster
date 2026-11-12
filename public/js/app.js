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
            form.submit();
        };
        input.addEventListener('change', send);

        // Files dragged from the computer are handled by the documents module below (point 85: folder rows, folder
        // windows, the menu's drop field). Never turn a dragged document preview into a fresh upload, even if a
        // browser still starts a native drag despite the non-draggable image and CSS rules.
        document.addEventListener('dragstart', event => {
            if (!(event.target instanceof Element) || !event.target.closest('.thumb-frame img') || !event.dataTransfer) {
                return;
            }
            event.dataTransfer.setData('application/x-dochamster-preview', '1');
            event.preventDefault();
        }, true);
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
                history.replaceState({ window: url.href, id: scope.dataset?.windowId }, '', url);
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
        // A label that changes while shown (maximise / restore) updates the tooltip.
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
        // Point 48 / A36: every dialog closes with an X at the top right instead of "Abbrechen".
        const addX = root => (root.matches?.('.dialog') ? [root] : [...root.querySelectorAll('[data-dialog] .dialog, .dialog-backdrop .dialog')]).forEach(box => {
            if (box.querySelector(':scope > .dialog-x') || box.closest('[data-update-progress], .update-progress')) return;
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'icon-button button-small dialog-x';
            x.setAttribute('data-dialog-close', '');
            x.setAttribute('aria-label', document.documentElement.lang === 'en' ? 'Close' : 'Schließen');
            x.innerHTML = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>';
            box.prepend(x);
        });
        addX(document);
        DocHamster.on('window:open', root => addX(root));
        const open = button => {
            const name = `[data-dialog="${button.dataset.dialogOpen}"]`;
            const dialog = button.closest('section[data-window]')?.querySelector(name) ?? document.querySelector(name);
            const heading = dialog.querySelector('[data-dialog-heading]');
            if (heading && button.dataset.dialogTitle) {
                heading.textContent = button.dataset.dialogTitle;
            }
            addX(dialog);
            dialog.hidden = false;
            dialog.querySelector('input:not([type="hidden"]), select, textarea, button:not([data-dialog-close]), a, [data-dialog-close]')?.focus();
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
    // data-window in a window closes all windows right of that window and opens the new one on the right.
    // Widths and strips come from layout() (points 54, 55: Finder-like column view, see there). The address
    // follows the active window (the last one opened); the history survives a reload (per tab).
    // Modules only deliver <x-window> content and listen to DocHamster.on('window:open', root => …).
    // ---------------------------------------------------------------------------------------------
    const WINDOWS = 'dh-windows';
    const newKey = () => Math.random().toString(36).slice(2, 8);
    // One window = one instance of a module for one record; the same record can be open twice (point 40). So every
    // id in a window gets the window's own suffix, and the references to it (label for, form, aria-*, #links,
    // data-copy/-focus) and the dialog names follow. Modules write plain ids; this keeps them unique on the page.
    const REFS = ['for', 'form', 'list', 'headers', 'aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns', 'aria-activedescendant', 'aria-details', 'aria-errormessage', 'data-copy', 'data-focus'];
    const isolate = (root, key) => {
        const ids = new Map();
        root.querySelectorAll('[id]').forEach(el => {
            ids.set(el.id, `${el.id}--${key}`);
            el.id = `${el.id}--${key}`;
        });
        const dialogs = new Map();
        root.querySelectorAll('[data-dialog]').forEach(el => {
            if (el.dataset.dialog) {
                dialogs.set(el.dataset.dialog, `${el.dataset.dialog}--${key}`);
                el.dataset.dialog = `${el.dataset.dialog}--${key}`;
            }
        });
        root.querySelectorAll('[data-dialog-open]').forEach(el => {
            el.dataset.dialogOpen = dialogs.get(el.dataset.dialogOpen) ?? el.dataset.dialogOpen;
        });
        if (ids.size === 0) {
            return;
        }
        root.querySelectorAll(REFS.map(name => `[${name}]`).join(',')).forEach(el => REFS.forEach(name => {
            const value = el.getAttribute(name);
            const next = value?.split(/\s+/).map(token => ids.get(token) ?? token).join(' ');
            if (value !== null && next !== value) {
                el.setAttribute(name, next);
            }
        }));
        root.querySelectorAll('a[href^="#"]').forEach(link => {
            const to = ids.get(link.getAttribute('href').slice(1));
            if (to) {
                link.setAttribute('href', `#${to}`);
            }
        });
    };
    const isolateWindow = win => {
        if (win.dataset.windowKey === undefined) {
            win.dataset.windowKey = newKey();
            isolate(win, win.dataset.windowKey);
        }
    };

    const setupWindows = () => {
        // A choice in the main menu starts a new row: the stored history of this tab is dropped (point 40).
        document.addEventListener('click', event => {
            if (event.target.closest('.nav-list a[href]')) {
                try { sessionStorage.removeItem(WINDOWS); } catch { /* nothing stored */ }
            }
        });
        const box = document.querySelector('[data-windows]');
        if (!box) {
            return;
        }
        const KEY = WINDOWS;
        const wins = () => [...box.querySelectorAll(':scope > section[data-window]')];
        const byUrl = url => wins().find(win => win.dataset.windowUrl === url);
        // Several windows can show the same address; the history entry names the window itself.
        const byState = state => wins().find(win => state?.id && win.dataset.windowId === state.id) ?? byUrl(state?.window ?? location.href);
        const stateOf = win => ({ window: win.dataset.windowUrl, id: win.dataset.windowId });
        const phone = matchMedia('(max-width: 63.99rem)');
        const smooth = change => {
            if (!document.startViewTransition || matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return Promise.resolve(change());
            }
            const transition = document.startViewTransition(change);
            transition.ready.catch(() => { /* skipped: the change still happened */ });
            return transition.finished;
        };
        const name = win => {
            win.dataset.windowId ??= `w${newKey()}`;
            win.style.viewTransitionName = `win-${win.dataset.windowId}`;
            if (win.dataset.windowMin) {
                win.style.setProperty('--win-min', win.dataset.windowMin);
            }
        };
        const active = () => box.querySelector(':scope > .is-active');
        // Maximise (points 72, 74, A119, A124): this window fills the row, all others become strips; a second click
        // (or a double-click on the title) brings the layout from before back. The maximised window is data-maxed;
        // `before` holds every window's strip state and width from the moment it was maximised.
        let before = null;
        const maxed = () => box.querySelector(':scope > section[data-maxed]');
        const showMax = (win, on) => {
            win.toggleAttribute('data-maxed', on);
            const button = win.querySelector('[data-window-maximise]');
            if (button) {
                const label = on ? button.dataset.labelRestore : button.dataset.labelMax;
                button.setAttribute('aria-pressed', String(on));
                button.setAttribute('aria-label', label);
                button.dataset.tooltip = label;
            }
            box.classList.toggle('is-maxed', maxed() !== null);
        };
        const reveal = win => requestAnimationFrame(() => win.scrollIntoView({ inline: 'nearest', block: 'nearest', behavior: 'smooth' }));

        // Widths like Apple's split view (NSSplitView; points 54, 55, 64, A91–A94). The row never scrolls sideways:
        // the windows always share exactly the room there is. Every window declares min, optimal and max width and a
        // priority (data-window-min/-opt/-max/-priority from <x-window>). A width
        // dragged by hand (A97: data-window-hand, only this one window, kept in the tab's window history) is its
        // preferred width; a new window starts at its kind's optimal width. A window keeps its width from one layout
        // to the next; only the difference to the room is shared out:
        //  - too little room → windows shrink towards their min, lowest priority first (among equals from the left),
        //    the newest and the one just opened again last; still too little → the lowest-priority window becomes a
        //    strip (never the newest or the one just opened again);
        //  - more room → a strip the layout made opens again as soon as the wide windows at their min leave room for
        //    its min + 4rem (A96: hysteresis, almost where it collapsed), then windows grow back to their preferred
        //    width, then up to their max; the rest goes to the last wide window.
        // While an edge is dragged (sizer below) nothing else lays out until the pointer is let go.
        // Manual minimise (data-folded) stays a strip until it is opened again. A maximised window (data-maxed) takes the
        // whole row, all others are strips.
        let keep = null;
        let dragging = false;
        const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
        const px = (value, fallback) => {
            const n = parseFloat(value);
            return Number.isFinite(n) ? (String(value).trim().endsWith('px') ? n : n * rem) : fallback * rem;
        };
        const STRIP = 3.5 * rem;
        const SPARE = 4 * rem;
        // A97: widths were once stored per kind; that store is gone.
        try { localStorage.removeItem('dh-win-widths'); } catch { /* nothing stored */ }
        const measure = (win, at) => {
            const own = parseFloat(win.dataset.windowHand);
            const min = px(win.dataset.windowMin, 24);
            const pref = own > 0 ? own : Math.max(min, px(win.dataset.windowOpt, 32));
            return {
                win, at, pref, hand: own > 0,
                min: Math.min(min, pref),
                max: Math.max(pref, px(win.dataset.windowMax, 48)),
                prio: Number(win.dataset.windowPriority) || 1,
                folded: win.dataset.folded !== undefined,
            };
        };
        const byPrio = (a, b) => a.prio - b.prio || a.at - b.at;
        // Writes widths and strips; the last wide window takes rounding leftovers (is-fill). A95: every window is its
        // own stacking layer (view-transition-name), so each window lies above the next one – the right half of its
        // drag edge reaches over the neighbour and must not be covered by it.
        const paint = info => {
            box.classList.add('is-laid');
            const last = info.findLast(item => !item.strip);
            info.forEach((item, at) => {
                item.win.style.zIndex = String(info.length - at);
                item.win.classList.toggle('is-min', item.strip);
                item.win.classList.toggle('is-fill', item === last);
                if (!item.strip) {
                    item.win.style.setProperty('--win-w', `${item.w.toFixed(2)}px`);
                }
            });
        };
        const layout = (fresh = false) => {
            if (dragging) {
                return;
            }
            const all = wins();
            if (phone.matches || all.length === 0) {
                box.classList.remove('is-laid');
                all.forEach(win => {
                    win.style.removeProperty('--win-w');
                    win.classList.remove('is-fill');
                });
                return;
            }
            fresh ||= !box.classList.contains('is-laid');
            const avail = box.clientWidth;
            const big = maxed();
            if (big) {
                paint(all.map((win, at) => ({ win, at, strip: win !== big, w: avail - (all.length - 1) * STRIP })));
                fill(big).catch(() => { /* stays as it is */ });
                return;
            }
            const newest = all.at(-1);
            const info = all.map((win, at) => {
                const item = measure(win, at);
                const wasStrip = win.classList.contains('is-min');
                const cur = parseFloat(win.style.getPropertyValue('--win-w'));
                item.protect = win === keep || win === newest;
                // The window just opened (no width yet) or opened again keeps its width while others make room.
                item.hold = win === keep || (win === newest && (fresh || !(cur > 0)));
                item.strip = item.folded || (!fresh && wasStrip && win !== keep);
                item.w = !fresh && !wasStrip && cur > 0 ? cur : item.pref;
                return item;
            });
            const open = () => info.filter(item => !item.strip);
            const used = () => info.reduce((sum, item) => sum + (item.strip ? STRIP : item.w), 0);
            // A window that keeps its width keeps at most its preferred one (more was only leftover), so collapsing
            // and opening again (A96) measure the same.
            const shrink = (all = false) => {
                let need = used() - avail;
                open().sort((a, b) => a.protect - b.protect || byPrio(a, b)).forEach(item => {
                    const give = Math.min(item.w - (all || !item.hold ? item.min : Math.max(item.min, item.pref)), need);
                    if (give > 0) {
                        item.w -= give;
                        need -= give;
                    }
                });
                return need;
            };
            // Too little room: shrink by priority, then strips by priority.
            let need = shrink();
            let collapsed = false;
            while (need > 0) {
                const victim = open().filter(item => !item.protect).sort(byPrio)[0];
                if (!victim) {
                    break;
                }
                victim.strip = true;
                collapsed = true;
                need = shrink();
            }
            if (need > 0) {
                need = shrink(true);
            }
            // Only kept windows left and still too wide: they squeeze below their min (never below 8rem).
            if (need > 0) {
                const soft = open().reduce((sum, item) => sum + Math.max(0, item.w - 8 * rem), 0);
                open().forEach(item => { item.w -= soft > 0 ? Math.max(0, item.w - 8 * rem) * Math.min(1, need / soft) : 0; });
            }
            // More room: strips the layout made open again (with room to spare), then the windows grow.
            // A96: a strip opens again as soon as the wide windows at their min leave room for its min + 4rem – the
            // measure the collapse used, so it comes back about 4rem after the point where it went. A window that
            // keeps its width (just opened / opened again) counts with that width, one sized by hand with its hand
            // width (at most its current one), so a drag that snapped a window to a strip is not undone right away.
            const back = info.filter(item => item.strip && !item.folded).sort((a, b) => byPrio(b, a));
            if (back.length && !collapsed && !fresh) {
                const floor = item => (item.hold || item.hand ? Math.max(item.min, Math.min(item.w, item.pref)) : item.min);
                let spare = avail - info.reduce((sum, item) => sum + (item.strip ? STRIP : floor(item)), 0);
                const opened = back.filter(item => {
                    if (spare < item.min - STRIP + SPARE) {
                        return false;
                    }
                    item.strip = false;
                    item.w = item.min;
                    spare -= item.min - STRIP;
                    return true;
                });
                if (opened.length && shrink() > 0) {
                    shrink(true);
                }
            }
            let room = avail - used();
            const wide = open().sort((a, b) => b.protect - a.protect || byPrio(b, a));
            const grow = cap => wide.forEach(item => {
                const add = Math.min(cap(item) - item.w, room);
                if (add > 0) {
                    item.w += add;
                    room -= add;
                }
            });
            grow(item => item.pref);
            grow(item => item.max);
            const last = open().at(-1);
            if (room > 0 && last) {
                last.w += room;
            }
            paint(info);
            // Windows that became wide and were only a stored strip get their content.
            all.filter(win => win.dataset.lazy !== undefined && !win.classList.contains('is-min')).forEach(win => fill(win).then(() => layout()).catch(() => { /* stays a strip until clicked */ }));
        };

        // Dragging a window's edge (Package L, A91, A92, A94). Every step is computed from the state at drag start
        // plus the whole distance, so the edge stays under the pointer. Wider: the windows right of it give way,
        // lowest priority first (among equals from the left), each down to its min; then the edge stops, and 3rem
        // further the lowest-priority one (never the newest) snaps to a strip. It opens again at its
        // min as soon as the edge leaves room for it – a 3rem band in between, so it never flips back and forth. Narrower: the
        // neighbour on the right takes the room. Windows left of the edge never move. Nothing else lays out until
        // end() (pointerup); the last window has no edge.
        const SNAP = 3 * rem;
        const FLOOR = 12 * rem;
        DocHamster.windowSizer = win => {
            const all = wins();
            const at = all.indexOf(win);
            if (phone.matches || at < 0 || at === all.length - 1 || win.classList.contains('is-min') || maxed()) {
                return null;
            }
            dragging = true;
            const info = all.map((other, i) => {
                const item = measure(other, i);
                item.was = other.classList.contains('is-min');
                item.strip = item.was;
                item.w0 = other.getBoundingClientRect().width;
                item.w = item.w0;
                return item;
            });
            const self = info[at];
            const right = info.slice(at + 1);
            const total = self.w0 + right.reduce((sum, item) => sum + (item.was ? STRIP : item.w0), 0);
            // Strips the layout made (not minimised by hand) right of the edge count as snapped shut: dragging back
            // opens them at their min, highest priority first.
            right.filter(item => !item.was).forEach(item => { item.min = Math.min(item.min, item.w0); });
            const shut = right.filter(item => item.was && !item.folded).sort((a, b) => a.prio - b.prio || b.at - a.at);
            shut.forEach(item => {
                item.was = false;
                item.w0 = item.min;
            });
            const wide = right.filter(item => !item.was);
            const order = [...wide].sort(byPrio);
            const snaps = order.filter(item => item.win !== all.at(-1));
            const to = target => {
                const wanted = Math.max(FLOOR, target);
                let open = wide;
                let room = 0;
                let deficit = 0;
                for (let guard = 0; guard <= wide.length * 2; guard++) {
                    open = wide.filter(item => !shut.includes(item));
                    room = total - wanted - (right.length - open.length) * STRIP;
                    deficit = open.reduce((sum, item) => sum + item.min, 0) - room;
                    const next = snaps.find(item => !shut.includes(item));
                    const back = shut.at(-1);
                    if (deficit > SNAP && next) {
                        shut.push(next);
                    } else if (back && deficit + back.min - STRIP <= 0) {
                        shut.pop();
                    } else {
                        break;
                    }
                }
                let w = wanted;
                if (open.length === 0) {
                    w = total - right.length * STRIP;
                    room = 0;
                } else if (deficit > 0) {
                    w -= deficit;
                    room += deficit;
                }
                let need = open.reduce((sum, item) => sum + item.w0, 0) - room;
                right.forEach(item => {
                    item.w = item.w0;
                    item.strip = item.was || shut.includes(item);
                });
                if (need > 0) {
                    order.filter(item => open.includes(item)).forEach(item => {
                        const give = Math.min(item.w - item.min, need);
                        if (give > 0) {
                            item.w -= give;
                            need -= give;
                        }
                    });
                } else if (open.length) {
                    open[0].w -= need;
                }
                self.w = w;
                paint(info);
                return w;
            };
            return {
                pref: self.pref,
                to,
                by: dx => to(self.w0 + dx),
                end: () => {
                    dragging = false;
                    layout();
                    remember();
                },
            };
        };
        const remember = () => {
            try {
                sessionStorage.setItem(KEY, JSON.stringify({
                    list: wins().map(win => ({ id: win.dataset.windowId, url: win.dataset.windowUrl, title: win.dataset.windowTitle, icon: win.querySelector('.win-strip .strip-label > .icon')?.outerHTML ?? '', folded: win.dataset.folded !== undefined, maxed: win.dataset.maxed !== undefined, closable: win.querySelector('[data-window-close]') !== null, kind: win.dataset.windowKind, wmin: win.dataset.windowMin, wopt: win.dataset.windowOpt, wmax: win.dataset.windowMax, prio: win.dataset.windowPriority, hand: win.dataset.windowHand })),
                    active: active()?.dataset.windowId ?? null,
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
                history.pushState(stateOf(win), '', win.dataset.windowUrl);
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
            if (item.id) {
                win.dataset.windowId = item.id;
            }
            win.dataset.lazy = '';
            // The width values come along, so the layout treats the strip like the loaded window (point 54).
            [['windowKind', item.kind], ['windowMin', item.wmin], ['windowOpt', item.wopt], ['windowMax', item.wmax], ['windowPriority', item.prio], ['windowHand', item.hand]]
                .forEach(([key, value]) => { if (value) win.dataset[key] = value; });
            win.toggleAttribute('data-maxed', !!item.maxed);
            // The same strip as a server window has: arrow, icon and title.
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
        const filling = new WeakMap();
        const fill = win => {
            if (win.dataset.lazy === undefined) {
                return Promise.resolve(win);
            }
            if (!filling.has(win)) {
                filling.set(win, load(win.dataset.windowUrl).then(full => {
                    full.dataset.windowId = win.dataset.windowId;
                    isolateWindow(full);
                    name(full);
                    full.classList.toggle('is-min', win.classList.contains('is-min'));
                    full.classList.toggle('is-active', win.classList.contains('is-active'));
                    full.toggleAttribute('data-folded', win.dataset.folded !== undefined);
                    full.style.setProperty('--win-w', win.style.getPropertyValue('--win-w'));
                    full.style.zIndex = win.style.zIndex;
                    if (win.dataset.windowHand) {
                        full.dataset.windowHand = win.dataset.windowHand;
                    }
                    full.classList.toggle('is-fill', win.classList.contains('is-fill'));
                    full.toggleAttribute('data-maxed', win.dataset.maxed !== undefined);
                    if (keep === win) {
                        keep = full;
                    }
                    win.replaceWith(full);
                    DocHamster.emit('window:open', full);
                    return full;
                }));
            }
            return filling.get(win);
        };

        // A strip becomes wide again (point 54.4): it stays wide, the others make room by the same rules.
        const restore = (win, push = true) => fill(win).then(full => smooth(() => {
            unmax(false);
            delete full.dataset.folded;
            keep = full;
            focus(full, push);
            layout();
        }).then(() => reveal(full))).catch(() => { location.href = win.dataset.windowUrl; });

        // A link in a window: everything right of that window closes, the new one opens on the right (points 40.2,
        // 40.3); which windows become strips decides the layout (point 54). Always a new window – also when the
        // same record is open already (Explorer-like instances, point 40).
        const open = (url, from) => load(url).then(win => {
            isolateWindow(win);
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
                }
                unmax(false);
                box.append(win);
                keep = null;
                focus(win);
                layout();
            }).then(() => {
                DocHamster.emit('window:open', win);
                reveal(win);
            });
        }).catch(() => { location.href = url; });

        // Maximise / back (points 72, 74). unmax(true) puts every window back as it was before; unmax(false) only
        // ends the state (something else lays out next: a window opens, a strip is clicked, a window goes).
        const unmax = (back = true) => {
            const big = maxed();
            if (!big) {
                return;
            }
            showMax(big, false);
            if (back && before) {
                wins().forEach(other => {
                    const was = before.get(other);
                    if (was) {
                        other.classList.toggle('is-min', was.strip);
                        if (was.w) {
                            other.style.setProperty('--win-w', was.w);
                        }
                    }
                });
            }
            before = null;
        };
        const maximise = win => smooth(() => {
            if (win.dataset.maxed !== undefined) {
                unmax(true);
            } else {
                unmax(false);
                before = new Map(wins().map(other => [other, { strip: other.classList.contains('is-min'), w: other.style.getPropertyValue('--win-w') }]));
                delete win.dataset.folded;
                showMax(win, true);
                keep = win;
                focus(win);
            }
            layout();
            remember();
        });

        // Minimise (point 42): the window becomes a strip; it stays one until it is opened again.
        const fold = win => smooth(() => {
            unmax(win.dataset.maxed !== undefined);
            win.dataset.folded = '';
            if (keep === win) {
                keep = null;
            }
            layout();
            if (win.classList.contains('is-active')) {
                const all = wins();
                const at = all.indexOf(win);
                const next = all.filter(other => !other.classList.contains('is-min'))
                    .sort((a, b) => Math.abs(all.indexOf(a) - at) - Math.abs(all.indexOf(b) - at))[0];
                if (next) {
                    focus(next, false);
                    history.replaceState(stateOf(next), '', next.dataset.windowUrl);
                    return;
                }
            }
            remember();
        });

        // The X: only this window goes; the layout runs again. If it was the active one, the nearest wide window
        // takes over; if no wide window is left, the nearest strip opens.
        const close = win => {
            const wasActive = win.classList.contains('is-active');
            const all = wins();
            const at = all.indexOf(win);
            const nearest = list => [...list].sort((a, b) => Math.abs(all.indexOf(a) - at) - Math.abs(all.indexOf(b) - at) || all.indexOf(a) - all.indexOf(b))[0];
            let wide = null;
            let next = null;
            DocHamster.emit('window:close', win);
            if (keep === win) {
                keep = null;
            }
            return smooth(() => {
                unmax(win.dataset.maxed !== undefined);
                win.remove();
                layout();
                const rest = wins();
                wide = nearest(rest.filter(other => !other.classList.contains('is-min')));
                next = wide ?? nearest(rest);
                if (wasActive && wide) {
                    focus(wide, false);
                    history.replaceState(stateOf(wide), '', wide.dataset.windowUrl);
                } else {
                    remember();
                }
            }).then(() => {
                if (!wide && next) {
                    restore(next, false).then(() => history.replaceState(stateOf(next), '', next.dataset.windowUrl));
                }
            });
        };

        // Start: the windows from the server, plus the rest of this tab's history (A62). Which ones are wide
        // decides the layout (point 54); a window minimised by hand stays a strip.
        wins().forEach(name);
        let saved = null;
        try {
            saved = JSON.parse(sessionStorage.getItem(KEY));
        } catch { /* fresh history */ }
        const current = wins().at(-1);
        if (saved && saved.list.some(item => item.url === current.dataset.windowUrl)) {
            // Each server window takes one stored place; the same address can be stored twice (point 40).
            const pool = wins().filter(win => win !== current);
            const mine = saved.list.find(item => item.id && item.id === saved.active && item.url === current.dataset.windowUrl)
                ?? saved.list.findLast(item => item.url === current.dataset.windowUrl);
            const order = saved.list.map(item => {
                const at = item === mine ? -1 : pool.findIndex(win => win.dataset.windowUrl === item.url);
                const win = item === mine ? current : (at >= 0 ? pool.splice(at, 1)[0] : placeholder(item));
                if (item.id) {
                    win.dataset.windowId = item.id;
                }
                if (item.hand) {
                    win.dataset.windowHand = item.hand;
                }
                win.toggleAttribute('data-folded', !!item.folded && win !== current);
                showMax(win, !!item.maxed && !item.folded);
                return win;
            });
            wins().filter(win => !order.includes(win)).forEach(win => order.push(win));
            order.forEach(win => {
                name(win);
                box.append(win);
            });
        }
        keep = current;
        wins().filter(win => win.dataset.lazy === undefined).forEach(isolateWindow);
        wins().filter(win => win.dataset.lazy === undefined).forEach(win => DocHamster.emit('window:open', win));
        layout();
        focus(current, false);
        history.replaceState(stateOf(current), '', location.href);
        reveal(current);

        box.addEventListener('click', event => {
            const win = event.target.closest('section[data-window]');
            if (event.target.closest('[data-window-maximise]')) {
                maximise(win);
            } else if (event.target.closest('[data-window-close]')) {
                event.preventDefault();
                close(win);
            } else if (event.target.closest('[data-window-fold]')) {
                fold(win);
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
        // A double-click on a window's title maximises it or brings the layout back (Windows-like, A124).
        box.addEventListener('dblclick', event => {
            const win = event.target.closest('section[data-window]');
            if (win && !phone.matches && event.target.closest('.win-title')) {
                maximise(win);
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
            const win = byState(event.state);
            if (win) {
                restore(win, false);
            } else {
                location.reload();
            }
        });
        phone.addEventListener('change', () => {
            layout();
            focus(active() ?? wins().at(-1), false);
        });
        // The room changes (browser window, menu folded) or a width was dragged by hand (Package L): lay out again.
        let frame = 0;
        new ResizeObserver(() => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => layout());
        }).observe(box.parentElement ?? box);
        document.addEventListener('dh:win-layout', () => {
            layout();
            remember();
        });
        // A window changed its own address (search, A104): the stored history keeps it.
        document.addEventListener('dh:window-url', remember);
    };

    // ---------------------------------------------------------------------------------------------
    // Search field in a window head (A104): <x-window search="…">. Typing reloads that window with ?q=…
    // (same fetch as window links) and swaps only its body, so the field keeps focus and caret. The address
    // follows with replaceState (no history entry per key). What is found is up to the module (it reads q).
    // ---------------------------------------------------------------------------------------------
    // A window loads its own address again (search A104, view point 52): only `part` of it (.win-body or
    // .win-frame) is swapped, the window keeps its place, id and suffix; the address follows when it is active.
    DocHamster.reloadWindow = (win, url, part = '.win-body', signal = undefined) => fetch(url, { credentials: 'same-origin', signal, headers: { 'X-DocHamster-Window': '1', Accept: 'text/html' } })
        .then(response => (response.ok ? response.text() : Promise.reject(response)))
        .then(html => {
            const found = [...new DOMParser().parseFromString(html, 'text/html').querySelectorAll('section[data-window]')].pop();
            const fresh = found?.querySelector(part);
            const old = win.querySelector(part);
            if (!fresh || !old) {
                return Promise.reject(new Error('no window part'));
            }
            const next = document.importNode(fresh, true);
            if (win.dataset.windowKey) {
                isolate(next, win.dataset.windowKey);
            }
            old.replaceWith(next);
            win.dataset.windowUrl = url.href;
            if (win.classList.contains('is-active') || !win.closest('[data-windows]')) {
                history.replaceState({ window: url.href, id: win.dataset.windowId }, '', url);
            }
            document.dispatchEvent(new Event('dh:window-url'));
            DocHamster.emit('window:open', next);
            return next;
        });

    const setupWindowSearch = () => {
        const runs = new WeakMap();
        const fieldOf = form => form.querySelector('input[name="q"]');
        const search = form => {
            const win = form.closest('section[data-window]');
            const field = fieldOf(form);
            const term = field.value.trim();
            const run = runs.get(form) ?? {};
            clearTimeout(run.timer);
            run.abort?.abort();
            form.querySelector('[data-window-search-clear]')?.toggleAttribute('hidden', field.value === '');
            const url = new URL(win?.dataset.windowUrl ?? location.href, location.href);
            if ((url.searchParams.get('q') ?? '') === term) {
                return;
            }
            if (term === '') {
                url.searchParams.delete('q');
            } else {
                url.searchParams.set('q', term);
            }
            url.searchParams.delete('page'); // a new search starts on its first page
            if (!win) {
                location.href = url.href;
                return;
            }
            run.abort = new AbortController();
            runs.set(form, run);
            DocHamster.reloadWindow(win, url, '.win-body', run.abort.signal).catch(error => {
                if (error?.name !== 'AbortError') {
                    location.href = url.href;
                }
            });
        };
        const later = form => {
            const run = runs.get(form) ?? {};
            clearTimeout(run.timer);
            run.timer = setTimeout(() => search(form), 300);
            runs.set(form, run);
        };
        const clear = form => {
            fieldOf(form).value = '';
            search(form);
            fieldOf(form).focus();
        };
        document.addEventListener('input', event => {
            const form = event.target.closest('[data-window-search]');
            if (form && event.target === fieldOf(form)) {
                form.querySelector('[data-window-search-clear]')?.toggleAttribute('hidden', event.target.value === '');
                later(form);
            }
        });
        document.addEventListener('submit', event => {
            const form = event.target.closest('[data-window-search]');
            if (form) {
                event.preventDefault();
                search(form);
            }
        });
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-window-search-clear]');
            if (button) {
                clear(button.closest('[data-window-search]'));
            }
        });
        // Escape in a filled field clears the search; nothing else reacts to that key then.
        document.addEventListener('keydown', event => {
            const form = event.target.closest?.('[data-window-search]');
            if (event.key === 'Escape' && form && fieldOf(form).value !== '') {
                event.preventDefault();
                event.stopImmediatePropagation();
                clear(form);
            }
        }, true);
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
    // the columns, a column by its head; the order is saved. The whole board is one module (points 71, 73): it can be
    // open several times side by side, so everything here works per board and looks only inside its own window.
    const setupBoard = board => {
        board.dataset.ready = '';
        const scope = board.closest('section[data-window]') ?? document;
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
        scope.querySelectorAll('[data-note-lock]').forEach(box => {
            const fields = box.form.querySelectorAll('[data-note-fields]');
            const locked = fields[0]?.disabled ?? false;
            box.addEventListener('change', () => fields.forEach(set => { set.disabled = locked && box.checked; }));
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
            // Point 50 (A40): while a note is dragged, every column shows its background as a drop target.
            board.classList.toggle('is-note-dragging', dragged.matches('.note'));
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
                if (!list) {
                    return;
                }
                // A sorted column (A103) has no hand order: a note can join it (at the end) but not move inside it.
                if (list.closest('.kanban-col').dataset.sort !== 'manual') {
                    if (dragged.parentElement === list) {
                        if (dragged.nextElementSibling !== noteGap) {
                            dragged.after(noteGap);
                        }
                    } else if (list.lastElementChild !== noteGap) {
                        list.append(noteGap);
                    }
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
                const stays = dragged.parentElement === list && list.closest('.kanban-col').dataset.sort !== 'manual';
                list.insertBefore(dragged, noteGap);
                if (stays) {
                    return;
                }
                counts();
                send(dragged.dataset.noteMove, {
                    column: list.dataset.kanbanCards,
                    order: [...list.querySelectorAll(':scope > .note')].map(card => card.dataset.noteId),
                }).then(response => { if (!response.ok) { failed(); } }).catch(failed);
            } else if (columnGap.parentElement) {
                board.insertBefore(dragged, columnGap);
                saveColumns();
            }
        });
        board.addEventListener('dragend', () => {
            noteGap.remove();
            columnGap.remove();
            dragged?.classList.remove('is-dragging');
            board.classList.remove('is-note-dragging');
            board.querySelectorAll('[draggable="true"]').forEach(el => { el.draggable = false; });
            dragged = null;
        });
        document.addEventListener('pointerup', () => {
            if (!dragged && board.isConnected) {
                board.querySelectorAll('[draggable="true"]').forEach(el => { el.draggable = false; });
            }
        });

        // Column ⋮ menu (A102): "Nach links" / "Nach rechts" move the column one place and save the order like a drag.
        const columnsOf = () => [...board.querySelectorAll(':scope > .kanban-col[data-column-id]')];
        function saveColumns() {
            const columns = columnsOf();
            columns.forEach((column, i) => {
                column.querySelectorAll('[data-column-move]').forEach(button => {
                    button.disabled = button.dataset.columnMove === '-1' ? i === 0 : i === columns.length - 1;
                });
            });
            send(board.dataset.columnsOrder, { order: columns.map(column => column.dataset.columnId) })
                .then(response => { if (!response.ok) { failed(); } }).catch(failed);
        }
        board.addEventListener('click', event => {
            const button = event.target.closest('[data-column-move]');
            const column = button?.closest('.kanban-col');
            if (!column || button.disabled) {
                return;
            }
            const columns = columnsOf();
            const to = columns[columns.indexOf(column) + Number(button.dataset.columnMove)];
            if (to) {
                to.insertAdjacentElement(button.dataset.columnMove === '-1' ? 'beforebegin' : 'afterend', column);
                saveColumns();
            }
        });
        // A115: a double-click on free space in a column (not a note, not the head) opens the new-note dialog for it.
        board.addEventListener('dblclick', event => {
            const column = event.target.closest('.kanban-col[data-column-id]');
            if (!column || event.target.closest('.note, .kanban-head, button, a, input, textarea, select, .kanban-gap')) {
                return;
            }
            column.querySelector('[data-kanban-add]')?.click();
        });


        // Edit dialog: colour and opacity show on the note right away; closing without saving puts the old look back (A9).
        scope.querySelectorAll('[data-note-preview]').forEach(dialog => {
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
        new ResizeObserver(() => board.querySelectorAll('.note-area').forEach(grow)).observe(board);
    };
    DocHamster.on('window:open', root => root.querySelectorAll?.('[data-notes]:not([data-ready])').forEach(setupBoard));
    // "+ Notiz" in a column (or the toolbar) puts the new note into that column (A33) – of its own board.
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-note-column]');
        const field = (button?.closest('section[data-window]') ?? document).querySelector('[data-note-new-column]');
        if (button && field) {
            field.value = button.dataset.noteColumn;
            const color = field.form?.querySelector(`input[name="color"][value="${button.dataset.noteColor ?? ''}"]`);
            if (color) {
                color.checked = true;
            }
        }
    });
    // A column just added by "+" (point 94) starts its rename at once, its default name selected.
    DocHamster.on('window:open', root => root.querySelectorAll?.('[data-rename-now]').forEach(box => {
        box.removeAttribute('data-rename-now');
        box.click();
        const field = box.nextElementSibling;
        if (field?.matches('input, textarea')) {
            field.select();
        }
    }));

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
        setupDialogs();
        setupWindows();
        setupWindowSearch();
        setupColumns();
        setupScrollHint();
        setupBadges();
        setupTagSearch();
        setupToasts();
        setupAjaxForms();
        setupPreviewPick();
        // Pages without the window system: the whole page is the one window
        if (!document.querySelector('[data-windows]')) {
            DocHamster.emit('window:open', document);
        }
    });
})();

// Document viewer in the detail window: wheel zoom towards the mouse, drag to pan, slider 25-400 %,
// box selection with "Zoomen" / "Kopieren", double click back to fit. Delegated, so windows loaded later work too.
// PDFs (frame[data-pdf], point 33/A19): pdf.js draws the page into a canvas.thumb that takes the image's place;
// it is drawn again at device pixels x zoom after each zoom, so the text stays sharp. All state lives per frame.
(() => {
    const MIN = 0.25, MAX = 4;
    const states = new WeakMap();
    const pdfs = new WeakMap();
    const frameOf = el => {
        const frame = el?.closest?.('.detail-doc');
        return frame && frame.querySelector(':scope > .thumb') && frame.nextElementSibling?.matches('[data-doc-zoom]') ? frame : null;
    };
    const stateOf = frame => {
        let st = states.get(frame);
        if (!st) {
            st = { s: 1, x: 0, y: 0, img: frame.querySelector(':scope > .thumb'), bar: frame.nextElementSibling, box: null };
            states.set(frame, st);
        }
        return st;
    };
    // Point relative to the frame's padding box (where the box overlay and offsetLeft live).
    const point = (frame, e) => {
        const r = frame.getBoundingClientRect();
        return { x: e.clientX - r.left - frame.clientLeft, y: e.clientY - r.top - frame.clientTop };
    };
    // Point 17: the document never leaves the view – its edge stops at the frame's edge; smaller than the frame, it stays centred.
    const clamp = (frame, st) => {
        const fit = (pos, size, box, off) => size <= box ? (box - size) / 2 - off : Math.min(-off, Math.max(box - size - off, pos));
        st.x = fit(st.x, st.img.offsetWidth * st.s, frame.clientWidth, st.img.offsetLeft);
        st.y = fit(st.y, st.img.offsetHeight * st.s, frame.clientHeight, st.img.offsetTop);
    };
    const apply = (frame, st) => {
        clamp(frame, st);
        st.img.style.transformOrigin = '0 0';
        st.img.style.transform = st.s === 1 && !st.x && !st.y ? '' : `translate(${st.x}px, ${st.y}px) scale(${st.s})`;
        frame.classList.toggle('is-zoomed', st.s > 1);
        const pct = Math.round(st.s * 100);
        st.bar.querySelector('[data-zoom-range]').value = pct;
        st.bar.querySelector('[data-zoom-value]').textContent = pct + ' %';
        const pdf = pdfs.get(frame);
        if (pdf && pdf.drawn !== Math.max(1, st.s)) redraw(frame, pdf, 150);
    };
    // Zoom to scale s keeping the frame point p fixed.
    const zoomAt = (frame, st, s, p) => {
        s = Math.min(MAX, Math.max(MIN, s));
        const ox = st.img.offsetLeft, oy = st.img.offsetTop;
        const qx = (p.x - ox - st.x) / st.s, qy = (p.y - oy - st.y) / st.s;
        st.x = p.x - ox - s * qx;
        st.y = p.y - oy - s * qy;
        st.s = s;
        apply(frame, st);
    };
    const center = frame => ({ x: frame.clientWidth / 2, y: frame.clientHeight / 2 });
    const reset = (frame, st) => { st.s = 1; st.x = 0; st.y = 0; apply(frame, st); };
    const clearBox = st => { st.box?.remove(); st.box = null; };
    const clearAll = () => document.querySelectorAll('.doc-box').forEach(box => {
        const st = states.get(box.parentElement);
        if (st) clearBox(st); else box.remove();
    });
    const toast = text => {
        const el = document.createElement('div');
        el.className = 'toast';
        el.setAttribute('role', 'status');
        el.textContent = text;
        document.body.append(el);
        const leave = () => { el.classList.add('is-leaving'); setTimeout(() => el.remove(), 300); };
        el.addEventListener('click', leave);
        setTimeout(leave, 3000);
    };
    // Box (frame coords) to natural pixels of the shown picture (object-fit: contain).
    const crop = (st, b) => {
        const img = st.img, w = img.clientWidth, h = img.clientHeight;
        const nw = img.naturalWidth || img.width, nh = img.naturalHeight || img.height;
        const c = Math.min(w / nw, h / nh), cx = (w - nw * c) / 2, cy = (h - nh * c) / 2;
        const toNat = (px, py) => ({
            x: Math.min(nw, Math.max(0, ((px - img.offsetLeft - st.x) / st.s - cx) / c)),
            y: Math.min(nh, Math.max(0, ((py - img.offsetTop - st.y) / st.s - cy) / c)),
        });
        const a = toNat(b.x, b.y), z = toNat(b.x + b.w, b.y + b.h);
        return { x: a.x, y: a.y, w: z.x - a.x, h: z.y - a.y };
    };
    const copy = (st, b) => {
        const r = crop(st, b);
        if (r.w < 1 || r.h < 1 || !navigator.clipboard?.write || typeof ClipboardItem === 'undefined') return;
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(r.w);
        canvas.height = Math.round(r.h);
        canvas.getContext('2d').drawImage(st.img, r.x, r.y, r.w, r.h, 0, 0, canvas.width, canvas.height);
        const blob = new Promise(done => canvas.toBlob(done, 'image/png'));
        navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })])
            .then(() => toast(st.bar.dataset.labelCopied))
            .catch(() => {});
    };
    const zoomBox = (frame, st, b) => {
        const W = frame.clientWidth, H = frame.clientHeight;
        const s = Math.min(MAX, Math.max(MIN, st.s * Math.min(W / b.w, H / b.h)));
        const ox = st.img.offsetLeft, oy = st.img.offsetTop;
        const qx = (b.x + b.w / 2 - ox - st.x) / st.s, qy = (b.y + b.h / 2 - oy - st.y) / st.s;
        st.s = s;
        st.x = W / 2 - ox - s * qx;
        st.y = H / 2 - oy - s * qy;
        apply(frame, st);
    };
    const showActions = (frame, st, b) => {
        const actions = document.createElement('div');
        actions.className = 'doc-box-actions';
        const make = (label, run) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'button-small';
            btn.textContent = label;
            btn.addEventListener('click', e => { e.stopPropagation(); run(); clearBox(st); });
            actions.append(btn);
        };
        make(st.bar.dataset.labelZoom, () => zoomBox(frame, st, b));
        make(st.bar.dataset.labelCopy, () => copy(st, b));
        st.box.append(actions);
    };

    document.addEventListener('wheel', e => {
        const frame = frameOf(e.target);
        if (!frame) return;
        e.preventDefault();
        const st = stateOf(frame);
        clearBox(st);
        zoomAt(frame, st, st.s * Math.exp(-e.deltaY * (e.deltaMode ? 0.05 : 0.0015)), point(frame, e));
    }, { passive: false });

    document.addEventListener('input', e => {
        if (!e.target.matches?.('[data-zoom-range]')) return;
        const frame = frameOf(e.target.closest('[data-doc-zoom]').previousElementSibling);
        if (!frame) return;
        const st = stateOf(frame);
        clearBox(st);
        zoomAt(frame, st, e.target.value / 100, center(frame));
    });

    // Point 25: Bewegen (pan, default) or Markieren (box); Alt+drag marks in either mode.
    // Point 23: a click on the percent value resets to 100 %, like a double click on the slider.
    document.addEventListener('click', e => {
        const mode = e.target.closest?.('[data-zoom-mode]');
        if (mode) {
            const bar = mode.closest('[data-doc-zoom]');
            bar.querySelectorAll('[data-zoom-mode]').forEach(b => b.setAttribute('aria-pressed', String(b === mode)));
            bar.previousElementSibling.classList.toggle('is-selecting', mode.dataset.zoomMode === 'select');
            return;
        }
        const resetBtn = e.target.closest?.('[data-zoom-reset]');
        if (resetBtn) {
            const frame = frameOf(resetBtn.closest('[data-doc-zoom]').previousElementSibling);
            if (frame) { const st = stateOf(frame); clearBox(st); reset(frame, st); }
        }
    });
    document.addEventListener('dblclick', e => {
        if (!e.target.matches?.('[data-zoom-range]')) return;
        const frame = frameOf(e.target.closest('[data-doc-zoom]').previousElementSibling);
        if (frame) { const st = stateOf(frame); clearBox(st); reset(frame, st); }
    });

    document.addEventListener('pointerdown', e => {
        if (e.target.closest?.('.doc-box-actions')) return;
        clearAll();
        const frame = frameOf(e.target);
        if (!frame || e.button !== 0 || e.pointerType === 'touch') return;
        e.preventDefault();
        if (frame.tabIndex >= 0) frame.focus({ preventScroll: true });
        const st = stateOf(frame);
        const select = e.altKey || frame.classList.contains('is-selecting');
        const start = point(frame, e);
        let last = start, box = null;
        frame.setPointerCapture(e.pointerId);
        const move = ev => {
            const p = point(frame, ev);
            if (select) {
                const b = { x: Math.min(start.x, p.x), y: Math.min(start.y, p.y), w: Math.abs(p.x - start.x), h: Math.abs(p.y - start.y) };
                if (!box && b.w < 5 && b.h < 5) return;
                if (!box) { box = document.createElement('div'); box.className = 'doc-box'; frame.append(box); st.box = box; }
                box.b = b;
                Object.assign(box.style, { left: b.x + 'px', top: b.y + 'px', width: b.w + 'px', height: b.h + 'px' });
            } else {
                st.x += p.x - last.x;
                st.y += p.y - last.y;
                last = p;
                frame.classList.add('is-panning');
                apply(frame, st);
            }
        };
        const up = () => {
            frame.removeEventListener('pointermove', move);
            frame.removeEventListener('pointerup', up);
            frame.removeEventListener('pointercancel', up);
            frame.classList.remove('is-panning');
            if (box && box.b.w >= 5 && box.b.h >= 5) showActions(frame, st, box.b);
            else if (box) clearBox(st);
        };
        frame.addEventListener('pointermove', move);
        frame.addEventListener('pointerup', up);
        frame.addEventListener('pointercancel', up);
    });

    document.addEventListener('dblclick', e => {
        const frame = frameOf(e.target);
        if (!frame || e.target.closest('.doc-box-actions')) return;
        const st = stateOf(frame);
        clearBox(st);
        reset(frame, st);
    });

    document.addEventListener('keydown', e => { if (e.key === 'Escape') clearAll(); });

    // pdf.js (self-hosted in public/vendor/pdfjs, body[data-pdfjs]); loaded once, one worker for all documents.
    let pdfLib = null;
    const loadPdf = () => (pdfLib ??= (async () => {
        const base = document.body?.dataset.pdfjs;
        if (!base) throw new Error('pdf.js not available');
        const lib = await import(base + '/pdf.min.js');
        lib.GlobalWorkerOptions.workerSrc = base + '/pdf.worker.min.js';
        let worker = null;
        try { worker = new lib.PDFWorker({ name: 'dochamster' }); } catch { /* pdf.js makes its own */ }
        return { lib, base, worker };
    })());
    // Opens a PDF (also used by the browser text recognition). The file comes with the session cookie.
    const openPdf = async url => {
        const { lib, base, worker } = await loadPdf();
        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = new Uint8Array(await res.arrayBuffer());
        return lib.getDocument({ data, worker, enableXfa: false, wasmUrl: base + '/wasm/' }).promise;
    };
    DocHamster.openPdf = openPdf;

    const PDF_MAX_PX = 16e6; // canvas limit of mobile Safari
    const live = new Set();
    const drop = frame => {
        const pdf = pdfs.get(frame);
        if (!pdf) return;
        pdfs.delete(frame);
        live.delete(frame);
        clearTimeout(pdf.timer);
        pdf.task?.cancel();
        resized?.unobserve(frame);
        pdf.doc.destroy().catch(() => {});
    };
    const sweep = () => live.forEach(frame => { if (!frame.isConnected) drop(frame); });
    const draw = async (frame, pdf) => {
        const gen = ++pdf.gen;
        const st = states.get(frame);
        const level = Math.max(1, st?.s ?? 1);
        let page;
        try { page = await pdf.doc.getPage(pdf.page); } catch { return; }
        if (gen !== pdf.gen || !frame.isConnected) return;
        const one = page.getViewport({ scale: 1 });
        const cs = getComputedStyle(frame);
        const w = frame.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        const h = frame.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
        if (w <= 0 || h <= 0) return; // hidden window: drawn when it gets a size
        let scale = Math.min(w / one.width, h / one.height) * (window.devicePixelRatio || 1) * level;
        const px = one.width * one.height * scale * scale;
        if (px > PDF_MAX_PX) scale *= Math.sqrt(PDF_MAX_PX / px);
        const viewport = page.getViewport({ scale });
        const canvas = document.createElement('canvas');
        canvas.className = 'thumb doc-canvas';
        canvas.width = Math.max(1, Math.floor(viewport.width));
        canvas.height = Math.max(1, Math.floor(viewport.height));
        pdf.task?.cancel();
        const task = pdf.task = page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport });
        try { await task.promise; } catch { return; } // cancelled by a newer drawing, or failed: the old picture stays
        if (gen !== pdf.gen || !frame.isConnected) return;
        pdf.task = null;
        pdf.drawn = level;
        const old = frame.querySelector(':scope > .thumb, :scope > .icon');
        if (old) old.replaceWith(canvas); else frame.prepend(canvas);
        const now = stateOf(frame);
        now.img = canvas;
        apply(frame, now);
    };
    const redraw = (frame, pdf, wait) => {
        clearTimeout(pdf.timer);
        pdf.timer = setTimeout(() => draw(frame, pdf), wait);
    };
    const resized = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(entries => entries.forEach(entry => {
        const pdf = pdfs.get(entry.target);
        if (pdf) { pdf.drawn = null; redraw(entry.target, pdf, 150); }
    }));
    // A19: "Seite 1 von 3" with previous/next, only for more than one page.
    const showPages = (frame, pdf) => {
        const box = frame.nextElementSibling?.querySelector('[data-doc-pages]');
        if (!box) return;
        box.hidden = pdf.pages < 2;
        box.querySelector('[data-page-label]').textContent = box.dataset.label.replace(':pages', pdf.pages).replace(':page', pdf.page);
        box.querySelector('[data-page-step="-1"]').disabled = pdf.page <= 1;
        box.querySelector('[data-page-step="1"]').disabled = pdf.page >= pdf.pages;
    };
    const turn = (frame, step) => {
        const pdf = pdfs.get(frame);
        const to = pdf ? Math.min(pdf.pages, Math.max(1, pdf.page + step)) : 0;
        if (!pdf || to === pdf.page) return;
        pdf.page = to;
        const st = states.get(frame);
        if (st) { clearBox(st); st.s = 1; st.x = 0; st.y = 0; }
        pdf.drawn = null;
        showPages(frame, pdf);
        redraw(frame, pdf, 0);
    };
    const initPdf = async frame => {
        if (frame.dataset.pdfReady !== undefined) return;
        frame.dataset.pdfReady = '';
        sweep();
        let doc;
        try { doc = await openPdf(frame.dataset.pdf); } catch { return; } // the preview image stays
        if (!frame.isConnected) { doc.destroy().catch(() => {}); return; }
        const pdf = { doc, page: 1, pages: doc.numPages, gen: 0, drawn: null, timer: 0, task: null };
        pdfs.set(frame, pdf);
        live.add(frame);
        showPages(frame, pdf);
        if (resized) resized.observe(frame); else redraw(frame, pdf, 0);
    };
    DocHamster.on('window:open', root => root.querySelectorAll?.('.detail-doc[data-pdf]').forEach(initPdf));
    DocHamster.on('window:close', root => root.querySelectorAll?.('.detail-doc[data-pdf]').forEach(drop));
    document.addEventListener('click', e => {
        const btn = e.target.closest?.('[data-page-step]');
        const frame = btn?.closest('[data-doc-zoom]')?.previousElementSibling;
        if (frame) turn(frame, Number(btn.dataset.pageStep));
    });
    document.addEventListener('keydown', e => {
        if (e.key !== 'PageUp' && e.key !== 'PageDown') return;
        if (e.target.matches?.('input, textarea, select, [contenteditable]')) return;
        const frame = e.target.closest?.('.detail-doc[data-pdf]') ?? e.target.closest?.('[data-doc-zoom]')?.previousElementSibling;
        if (!frame || !pdfs.has(frame)) return;
        e.preventDefault();
        turn(frame, e.key === 'PageDown' ? 1 : -1);
    });
})();

/* Colour palette (points 14-15) */
(() => {
    const DocHamster = window.DocHamster;
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // Einstellungen → Farben: the palette rows are a sortable list of the framework; a move saves the order.
    DocHamster?.on('list:sorted', ({ list, ids }) => {
        const url = list.dataset.paletteOrder;
        if (!url) {
            return;
        }
        const body = new FormData();
        ids.forEach(id => body.append('order[]', id));
        fetch(url, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
            .then(response => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }
            })
            .catch(() => {
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

    // Note edit dialog: the picked palette colour (class c-rrggbb from tags.css) shows on the note right away;
    // closing without saving puts the old colour back.
    const colorClass = note => [...note.classList].find(name => /^c-[0-9a-f]{6}$/.test(name));
    const setupNotePreview = root => root.querySelectorAll?.('[data-note-preview]:not([data-palette-ready])').forEach(dialog => {
        dialog.dataset.paletteReady = '';
        const note = (dialog.closest('section[data-window]') ?? document).querySelector(`[data-note-id="${dialog.dataset.notePreview}"]`);
        const form = dialog.querySelector('form');
        if (!note || !form) {
            return;
        }
        let before = null;
        let saved = false;
        const show = name => {
            const now = colorClass(note);
            if (now) {
                note.classList.remove(now);
            }
            if (name) {
                note.classList.add(name);
            }
        };
        form.addEventListener('input', () => {
            const picked = form.querySelector('input[name="color"]:checked');
            if (picked && /^#[0-9a-f]{6}$/.test(picked.value)) {
                show(`c-${picked.value.slice(1)}`);
            }
        });
        form.addEventListener('submit', () => { saved = true; });
        new MutationObserver(() => {
            if (!dialog.hidden) {
                before = colorClass(note) ?? '';
                saved = false;
            } else if (before !== null && !saved) {
                show(before);
            }
        }).observe(dialog, { attributes: true, attributeFilter: ['hidden'] });
    });
    DocHamster.on('window:open', setupNotePreview);
})();

/* OCR fallback (point 18) */
// Reads receipt text in the browser with self-hosted tesseract.js when the
// server can't (body[data-ocr-browser]). One image at a time, only while the
// page is visible and idle, not on cellular/data saver/low battery, and not
// while paused in the menu (point 57, localStorage dh.ocr.paused, every tab).
// Point 56: three passes, the server hands out the lowest first; each pass
// replaces the text of the one before. Point 58/A60/A63: every pass has a time
// limit per document; A61: an engine that does not start is reported and
// retried after 5 minutes instead of spinning. A78: with 4+ cores a second
// engine reads pass-1 documents alongside; passes 2/3 stay one at a time.
// Point 61: results carry their duration; engine start goes to the OCR log.
(() => {
  const startOcr = () => {
    const base = document.body?.dataset.ocrBrowser;
    const nextUrl = document.body?.dataset.ocrNext;
    if (!base || !nextUrl || typeof Worker === 'undefined' || typeof WebAssembly === 'undefined') return;
    // side = long image side in px (0 = original), scale = PDF page scale, pause after each document, limit = time per document.
    const PASSES = {
        1: { side: 1000, scale: 1, pause: 0, limit: 2 * 60000 },
        2: { side: 2000, scale: 2, pause: 5000, limit: 5 * 60000 },
        3: { side: 0, scale: 3.5, pause: 30000, limit: 10 * 60000 },
    };
    const MAX_PIXELS = 24e6;
    const WAIT_MS = 60000;
    const ENGINE_MS = 60000;
    const ENGINE_RETRY_MS = 5 * 60000;
    const engineUrl = document.body?.dataset.ocrEngine;
    const logUrl = document.body?.dataset.ocrLog;
    const PAUSE_KEY = 'dh.ocr.paused';
    const TWO = (navigator.hardwareConcurrency ?? 1) >= 4;
    const skip = [];
    // Lane 0 reads every pass; lane 1 (A78) only pass 1 and only on 4+ cores.
    const lanes = [{ main: true, engine: null, running: null, active: true }, { main: false, engine: null, running: null, active: false }];
    const wakers = new Set();

    const paused = () => {
        try { return localStorage.getItem(PAUSE_KEY) === '1'; } catch { return false; }
    };
    // A sleep that "Fortsetzen" ends early.
    const sleep = ms => new Promise(resolve => {
        const done = () => { clearTimeout(timer); wakers.delete(done); resolve(); };
        const timer = setTimeout(done, ms);
        wakers.add(done);
    });
    const idle = () => new Promise(resolve => ('requestIdleCallback' in window
        ? window.requestIdleCallback(() => resolve(), { timeout: 30000 })
        : setTimeout(resolve, 1000)));
    const allowed = async () => {
        if (document.visibilityState !== 'visible') return false;
        const c = navigator.connection;
        if (c && (c.saveData === true || c.type === 'cellular')) return false;
        if (navigator.getBattery) {
            try {
                const b = await navigator.getBattery();
                if (!b.charging && b.level <= 0.2) return false;
            } catch { /* no battery info */ }
        }
        return true;
    };
    const loadLib = () => (window.Tesseract ? Promise.resolve() : new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = base + '/tesseract.min.js';
        s.onload = resolve;
        s.onerror = reject;
        document.head.append(s);
    }));
    // Rejects after ms; the timer is cleared either way.
    const within = (promise, ms, reason) => {
        let timer = null;
        const limit = new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(reason)), ms); });
        return Promise.race([promise, limit]).finally(() => clearTimeout(timer));
    };
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const post = (url, fields) => {
        if (!url) return;
        const body = new FormData();
        Object.entries(fields).forEach(([key, value]) => body.append(key, String(value)));
        fetch(url, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
            .catch(() => { /* only a hint */ });
    };
    // A61: the engine start has 60 s; a worker that comes up later is closed again.
    const getEngine = async lane => {
        if (lane.engine) return lane.engine;
        let late = false;
        const began = performance.now();
        const create = (async () => {
            await loadLib();
            return window.Tesseract.createWorker('deu+eng', 1, {
                workerPath: base + '/worker.min.js',
                corePath: base + '/core',
                langPath: base + '/lang',
                gzip: false,
                workerBlobURL: false,
                // Only the main lane feeds the progress in the menu.
                logger: lane.main ? m => document.dispatchEvent(new CustomEvent('dh:ocr-progress', { detail: m })) : () => {},
                errorHandler: () => { /* surfaces as a rejected job */ },
            });
        })();
        create.then(w => { if (late) w.terminate().catch(() => {}); }, () => {});
        try {
            lane.engine = await within(create, ENGINE_MS, 'timeout 60 s');
        } catch (e) {
            late = true;
            throw e;
        }
        // Once per lane and page, so pass 3 (engine closed between documents) does not flood the log.
        if (!lane.logged) post(logUrl, { event: 'engine_started', duration: Math.round(performance.now() - began) });
        lane.logged = true;
        return lane.engine;
    };
    const reportEngine = error => post(engineUrl, { reason: String(error?.message || error || 'unknown').slice(0, 300) });
    const stop = async lane => {
        if (!lane.engine) return;
        const e = lane.engine;
        lane.engine = null;
        try { await e.terminate(); } catch { /* already gone */ }
    };
    // Pause (point 57): the running documents are dropped unsaved and the engines closed, so the computer gets its resources back.
    const onPause = isPaused => {
        if (isPaused) {
            lanes.forEach(lane => {
                if (lane.running) lane.running.aborted = true;
                stop(lane);
            });
        } else {
            [...wakers].forEach(wake => wake());
        }
    };
    document.addEventListener('dh:ocr-pause', event => onPause(event.detail === true));
    window.addEventListener('storage', event => { if (event.key === PAUSE_KEY) onPause(event.newValue === '1'); });
    const check = run => { if (run.aborted || run.timedOut) throw new Error('stopped'); };
    // Images: scaled down to the pass's long side (pass 3: original), capped for memory.
    const readImage = async (lane, url, side, run) => {
        if (typeof createImageBitmap === 'undefined') return (await (await getEngine(lane)).recognize(url)).data?.text ?? '';
        const res = await fetch(url, { credentials: 'same-origin' });
        if (!res.ok) throw new Error('image');
        const blob = await res.blob();
        const bitmap = await createImageBitmap(blob);
        try {
            check(run);
            const factor = Math.min(side > 0 ? side / Math.max(bitmap.width, bitmap.height) : 1, Math.sqrt(MAX_PIXELS / (bitmap.width * bitmap.height)));
            if (factor >= 1) return (await (await getEngine(lane)).recognize(blob)).data?.text ?? '';
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(bitmap.width * factor));
            canvas.height = Math.max(1, Math.round(bitmap.height * factor));
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            try {
                return (await (await getEngine(lane)).recognize(canvas)).data?.text ?? '';
            } finally {
                canvas.width = canvas.height = 0;
            }
        } finally {
            bitmap.close?.();
        }
    };
    // Point 34: PDFs page by page with pdf.js (document viewer block). A page that already carries text
    // gives that text; a scanned page is drawn at the pass's scale and read by tesseract. Pages joined by a blank line.
    // Point 69: gives the page count and how many pages came from the text layer (A106/A114) for the OCR log.
    const readPdf = async (lane, url, maxScale, run, info) => {
        if (!DocHamster.openPdf) throw new Error('pdf.js not available');
        const doc = await DocHamster.openPdf(url);
        try {
            const texts = [];
            const last = Math.min(doc.numPages, 50);
            info.pages = doc.numPages;
            info.layer = 0;
            for (let n = 1; n <= last; n++) {
                check(run);
                const page = await doc.getPage(n);
                const layer = await page.getTextContent();
                const own = layer.items.map(item => (item.str ?? '') + (item.hasEOL ? '\n' : ' ')).join('').trim();
                if (own.replace(/\s/g, '').length >= 20) {
                    texts.push(own);
                    info.layer++;
                } else {
                    const one = page.getViewport({ scale: 1 });
                    const scale = Math.min(maxScale, Math.sqrt(MAX_PIXELS / (one.width * one.height)));
                    const viewport = page.getViewport({ scale });
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.floor(viewport.width);
                    canvas.height = Math.floor(viewport.height);
                    await page.render({ canvas, canvasContext: canvas.getContext('2d'), viewport }).promise;
                    const { data } = await (await getEngine(lane)).recognize(canvas);
                    texts.push((data?.text ?? '').trim());
                    canvas.width = canvas.height = 0;
                }
                page.cleanup();
            }
            return texts.filter(Boolean).join('\n\n');
        } finally {
            doc.destroy().catch(() => {});
        }
    };
    const announce = (lane, job) => { if (lane.main) document.dispatchEvent(new CustomEvent('dh:ocr-job', { detail: job })); };

    // One document of the lowest open pass (A77: the server picks at random among them); gives the pause before
    // the next one, or null when nothing is left. The second lane asks for pass 1 only.
    const step = async lane => {
        const url = new URL(nextUrl, location.href);
        skip.forEach(id => url.searchParams.append('skip[]', id));
        if (!lane.main) url.searchParams.set('only', '1');
        const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (res.status !== 200) return null;
        const job = await res.json();
        const pass = PASSES[job.pass] ? job.pass : 3;
        if (!lane.main && pass !== 1) return null;
        // A61: no engine, no document – the reason goes to the server, the next try comes in 5 minutes.
        try {
            await getEngine(lane);
        } catch (e) {
            reportEngine(e);
            await stop(lane);
            return lane.main ? ENGINE_RETRY_MS : null;
        }
        // A78: a pass-1 document on the main lane wakes the second lane.
        if (lane.main && pass === 1 && TWO && !lanes[1].active) runLane(lanes[1]);
        const run = { aborted: false, timedOut: false };
        lane.running = run;
        announce(lane, job);
        const body = new FormData();
        body.append('pass', String(pass));
        const began = performance.now();
        const info = { pages: null, layer: null };
        try {
            const read = job.type === 'application/pdf'
                ? readPdf(lane, job.file, PASSES[pass].scale, run, info)
                : readImage(lane, job.file, PASSES[pass].side, run);
            read.catch(() => {});
            body.append('text', await within(read, PASSES[pass].limit, 'timeout'));
        } catch (e) {
            // Point 58: over the time limit the engine is closed, so the computer gets its resources back.
            if (e?.message === 'timeout') {
                run.timedOut = true;
                await stop(lane);
            }
            body.append('failed', '1');
            body.append('reason', String(e?.message || e || 'unknown').slice(0, 290));
        }
        body.append('duration', String(Math.round(performance.now() - began)));
        if (info.pages !== null) body.append('pages', String(info.pages));
        if (info.layer !== null) body.append('layer', String(info.layer));
        lane.running = null;
        if (run.aborted) {
            // Paused: nothing saved, the document comes again after "Fortsetzen".
            announce(lane, null);
            return 0;
        }
        try {
            const saved = await fetch(job.save, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' });
            if (!saved.ok) skip.push(job.id);
        } catch {
            skip.push(job.id);
        }
        announce(lane, null);
        return PASSES[pass].pause;
    };

    // The main lane runs until nothing is left; the second lane until no pass-1 document is left.
    const runLane = async lane => {
        lane.active = true;
        try {
            if (lane.main) await sleep(5000);
            for (;;) {
                if (paused()) {
                    await stop(lane);
                    await sleep(WAIT_MS);
                    continue;
                }
                await idle();
                if (paused()) continue;
                if (!(await allowed())) {
                    await stop(lane);
                    await sleep(WAIT_MS);
                    continue;
                }
                let pause = null;
                try { pause = await step(lane); } catch { pause = null; }
                if (pause === null) { await stop(lane); return; }
                // Long pauses (pass 3) close the engine meanwhile.
                if (pause >= 30000) await stop(lane);
                if (pause > 0) await sleep(pause);
            }
        } finally {
            lane.active = false;
        }
    };
    runLane(lanes[0]);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startOcr);
  else startOcr();
})();

/* Documents and folders: rows, drag and drop, uploads by drop (points 75-87, A131-A180) */
// One module for every Dokumente, folder and Papierkorb window, also for windows loaded later and several side by side:
// - row icons as tall as the two text lines (A144-A146): a tight viewBox from the symbol's own box;
// - row actions (A161, A174): pencil renames in place, the gear opens the properties / details as a window (plain link);
// - Ctrl/Shift picks several document rows (A132); a drag carries all picked ones;
// - documents dropped on a folder row (also in another window) or the free space of a folder window move there (point 75),
//   on the Papierkorb row they go into the trash (76), from the Papierkorb onto a folder they come back there (A135);
//   a toast says what happened and offers "Rückgängig" (A133);
// - folders dropped on the middle of a folder row go into it, between rows they take the new place (78, A153); never into
//   themselves (A138), never too deep (A139, a hint on drop), "Dokumente allgemein" and the Papierkorb stay (79, A168);
//   a folder on the Papierkorb row goes there with its content after a confirm (A134);
// - files from the computer: onto a folder row, a folder window or the menu's drop field (85, A169-A175).
// A drag starts only after a few pixels (A155), with a finger after a short hold; Escape cancels.
(() => {
    const DocHamster = window.DocHamster;
    if (!DocHamster) {
        return;
    }
    const html = document.documentElement;
    const config = () => document.querySelector('[data-dnd]')?.dataset ?? {};
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const fill = (template, values) => Object.entries(values).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value)), template ?? '');
    const docsLabel = count => fill(count === 1 ? config().docsOne : config().docsMany, { count });
    const still = matchMedia('(prefers-reduced-motion: reduce)');

    // --- Toast with an optional "Rückgängig" (A133) -------------------------------------------------------------
    const toast = (message, { error = false, undo = null } = {}) => {
        const el = document.createElement('div');
        el.className = error ? 'toast toast-error' : 'toast';
        el.setAttribute('role', error ? 'alert' : 'status');
        const textEl = document.createElement('span');
        textEl.textContent = message;
        el.append(textEl);
        const leave = () => {
            el.classList.add('is-leaving');
            setTimeout(() => el.remove(), 300);
        };
        if (undo) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button-quiet button-small toast-action';
            button.textContent = config().undo ?? 'Undo';
            button.addEventListener('click', event => {
                event.stopPropagation();
                leave();
                undo();
            }, { once: true });
            el.append(button);
        }
        el.addEventListener('click', leave);
        document.body.append(el);
        setTimeout(leave, undo ? 8000 : 4000);
    };
    DocHamster.toast = toast;

    const post = (url, data) => {
        const body = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) {
            Object.entries(data ?? {}).forEach(([key, value]) => {
                if (Array.isArray(value)) {
                    value.forEach(item => body.append(`${key}[]`, item));
                } else if (value !== null && value !== undefined) {
                    body.append(key, value);
                }
            });
        }
        return fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' } })
            .then(async response => {
                const json = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(json.message || config().failed || String(response.status));
                }
                return json;
            });
    };
    const fail = error => toast(error?.message || config().failed, { error: true });

    // Every Dokumente, folder and Papierkorb window loads its body again: counts, rows and names follow.
    const refresh = () => document.querySelectorAll('[data-windows] > section[data-window]').forEach(win => {
        if (['folder', 'trash'].includes(win.dataset.windowKind) && win.querySelector('.win-body')) {
            DocHamster.reloadWindow(win, new URL(win.dataset.windowUrl ?? location.href, location.href), '.win-body').catch(() => {});
        }
    });

    // Our own confirm (framework dialog, built here because the question depends on what was dropped).
    const confirmDialog = ({ title, text, ok }) => new Promise(resolve => {
        const backdrop = document.createElement('div');
        backdrop.className = 'dialog-backdrop';
        backdrop.hidden = true;
        const box = document.createElement('div');
        box.className = 'dialog';
        box.setAttribute('role', 'alertdialog');
        box.setAttribute('aria-modal', 'true');
        const h = document.createElement('h2');
        h.textContent = title;
        const p = document.createElement('p');
        p.textContent = text;
        const actions = document.createElement('div');
        actions.className = 'dialog-actions';
        const no = document.createElement('button');
        no.type = 'button';
        no.className = 'button-quiet';
        no.textContent = config().cancel ?? 'Cancel';
        const yes = document.createElement('button');
        yes.type = 'button';
        yes.className = 'button-danger';
        yes.textContent = ok;
        actions.append(no, yes);
        box.append(h, p, actions);
        backdrop.append(box);
        document.body.append(backdrop);
        const before = document.activeElement;
        const close = answer => {
            document.removeEventListener('keydown', key, true);
            backdrop.remove();
            before?.focus?.();
            resolve(answer);
        };
        const key = event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close(false);
            }
        };
        no.addEventListener('click', () => close(false));
        yes.addEventListener('click', () => close(true));
        backdrop.addEventListener('click', event => {
            if (event.target === backdrop) {
                close(false);
            }
        });
        document.addEventListener('keydown', key, true);
        backdrop.hidden = false;
        no.focus();
    });

    // --- Row icons as tall as the two text lines (A144-A146) ---------------------------------------------------
    // The symbol's own box (getBBox, measured once per symbol in a hidden probe) becomes its viewBox, so every symbol
    // fills the 38 px slot; the stroke stays as thin as elsewhere (vector-effect: non-scaling-stroke in app.css).
    const SLOT = 38;
    const boxes = new Map();
    const measure = svg => {
        const key = svg.innerHTML;
        if (!boxes.has(key)) {
            const probe = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            probe.setAttribute('class', 'fit-probe');
            probe.setAttribute('viewBox', '0 0 24 24');
            probe.innerHTML = key;
            probe.querySelectorAll('[stroke="none"]').forEach(node => node.remove());
            document.body.append(probe);
            const box = probe.getBBox();
            probe.remove();
            boxes.set(key, box.width > 0 && box.height > 0 ? { x: box.x - 1, y: box.y - 1, w: box.width + 2, h: box.height + 2 } : null);
        }
        return boxes.get(key);
    };
    const fitIcons = root => root.querySelectorAll?.('.row-icon > svg.icon:not(.is-fit)').forEach(svg => {
        const box = measure(svg);
        if (!box) {
            return;
        }
        const scale = Math.min(SLOT / box.w, SLOT / box.h);
        svg.setAttribute('viewBox', `${box.x} ${box.y} ${box.w} ${box.h}`);
        svg.style.width = `${box.w * scale}px`;
        svg.style.height = `${box.h * scale}px`;
        svg.classList.add('is-fit');
    });
    DocHamster.fitRowIcons = fitIcons;
    DocHamster.on('window:open', root => fitIcons(root ?? document));
    const fitAll = () => fitIcons(document);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fitAll);
    } else {
        fitAll();
    }

    // --- Rename in place (rows): folder rows (pencil, A161) and document rows (A174), rules A188-A190 ---------------
    // No X: Enter or ✓ saves; Escape or a click anywhere outside the row cancels. A long press on the name starts it too.
    const showRename = (row, on) => {
        const form = row?.querySelector('[data-folder-rename-form]');
        const name = row?.querySelector('[data-folder-name]');
        if (!form || !name) {
            return;
        }
        form.hidden = !on;
        name.hidden = on;
        row.classList.toggle('is-renaming', on);
        if (on) {
            const input = form.querySelector('input[name="name"]');
            input.focus();
            input.select();
        } else {
            form.reset();
        }
    };
    document.addEventListener('click', event => {
        const start = event.target.closest('[data-folder-rename]');
        if (start) {
            showRename(start.closest('li'), true);
        }
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && event.target.closest?.('[data-folder-rename-form]')) {
            showRename(event.target.closest('li'), false);
        }
    });
    const docRenameCancel = new WeakMap(); // open document field → its cancel
    document.addEventListener('pointerdown', event => {
        document.querySelectorAll('li.is-renaming[data-folder-row]').forEach(row => {
            if (!row.contains(event.target)) {
                showRename(row, false);
            }
        });
        document.querySelectorAll('.doc-rename').forEach(box => {
            if (!(box.closest('[data-doc]') ?? box.parentElement).contains(event.target)) {
                docRenameCancel.get(box)?.();
            }
        });
    }, true);
    // A184: a renamed folder or document gets its new title in every open window that shows it (head, strip, history).
    const retitle = (matches, name) => document.querySelectorAll('section[data-window]').forEach(win => {
        let url;
        try { url = new URL(win.dataset.windowUrl, location.href); } catch { return; }
        if (!matches(url)) return;
        win.dataset.windowTitle = name;
        win.setAttribute('aria-label', name);
        win.querySelectorAll(':scope .win-title').forEach(h => { h.textContent = name; h.title = name; });
        const label = win.querySelector(':scope > .win-strip .strip-label > span');
        if (label) label.textContent = name;
    });
    // A194: a folder changed anywhere (row rename, properties) follows at once in every open window: its rows (name,
    // colour classes, symbol) and the heads and strips of its windows (title, symbol). Moved: the folder windows reload.
    const tagCss = () => {
        const link = document.querySelector('link[rel="stylesheet"][href*="tags.css"]');
        if (!link) return;
        const next = link.cloneNode();
        const url = new URL(link.href, location.href);
        url.searchParams.set('v', String(Date.now()));
        next.href = url.href;
        next.addEventListener('load', () => link.remove(), { once: true });
        link.after(next);
    };
    const folderWindows = id => url => (id === 'trash'
        ? url.searchParams.get('folder') === 'trash' || /\/documents\/trash\/?$/.test(url.pathname)
        : url.searchParams.get('folder') === id && !url.searchParams.has('new'));
    const applyFolder = data => {
        if (!data?.id) return;
        const matches = folderWindows(data.id);
        retitle(matches, data.name);
        document.querySelectorAll(`[data-folder-row][data-id="${CSS.escape(data.id)}"]`).forEach(row => {
            row.dataset.name = data.name;
            const link = row.querySelector('[data-folder-name] a');
            if (link) link.textContent = data.name;
            const input = row.querySelector('[data-folder-rename-form] input[name="name"]');
            if (input) input.defaultValue = data.name;
            [...row.classList].filter(c => /^(row-color|c-[0-9a-f]{6})$/.test(c)).forEach(c => row.classList.remove(c));
            (data.rowClass ?? '').split(' ').filter(Boolean).forEach(c => row.classList.add(c));
            const slot = row.querySelector('.row-icon');
            if (data.icon && slot) {
                slot.innerHTML = data.icon;
                DocHamster.fitRowIcons?.(row);
            }
        });
        if (data.icon) {
            document.querySelectorAll('section[data-window]').forEach(win => {
                let url;
                try { url = new URL(win.dataset.windowUrl, location.href); } catch { return; }
                if (matches(url)) {
                    win.querySelectorAll(':scope > .win-frame > .win-head > svg.icon, :scope > .win-strip .strip-label > svg.icon').forEach(svg => { svg.outerHTML = data.icon; });
                }
            });
        }
        if (data.rowClass) tagCss(); // an own colour may be new to the page's colour classes
        if (data.moved) refresh();
    };
    DocHamster.on('properties:saved', ({ json }) => applyFolder(json.folder));
    document.addEventListener('submit', event => {
        const form = event.target.closest('[data-folder-rename-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const row = form.closest('li');
        post(form.action, new FormData(form)).then(json => {
            showRename(row, false);
            applyFolder(json.folder ?? { id: row.dataset.id, name: json.name });
            toast(json.message);
            refresh();
        }).catch(fail);
    });

    const CHECK = '<svg class="icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5l10 -10"/></svg>';
    const startDocRename = button => {
        const row = button.closest('[data-doc]');
        const title = row?.querySelector('[data-doc-title]');
        if (!title || row.querySelector('.doc-rename')) {
            return;
        }
        const box = document.createElement('span');
        box.className = 'doc-rename';
        const field = document.createElement('input');
        field.className = 'input-small';
        field.value = title.textContent.trim();
        field.maxLength = 250;
        field.setAttribute('aria-label', button.getAttribute('aria-label') ?? '');
        const ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'control-button';
        ok.setAttribute('aria-label', button.dataset.save ?? 'OK');
        ok.dataset.tooltip = button.dataset.save ?? 'OK';
        ok.innerHTML = CHECK;
        box.append(field, ok);
        const link = title.closest('a') ?? title;
        link.hidden = true;
        link.after(box);
        row.classList.add('is-renaming');
        field.focus();
        field.select();
        let done = false;
        const finish = save => {
            if (done) {
                return;
            }
            done = true;
            const value = field.value.trim();
            box.remove();
            link.hidden = false;
            row.classList.remove('is-renaming');
            if (!save || value === '' || value === title.textContent.trim()) {
                return;
            }
            const before = title.textContent;
            title.textContent = value;
            post(button.dataset.docRename, { field: 'title', value }).then(json => {
                const doc = (link.getAttribute('href') ?? '').match(/\/documents\/([^/?#]+)/)?.[1];
                if (doc) retitle(url => url.pathname.replace(/\/$/, '').endsWith('/documents/' + doc), value);
                toast(json.message);
                refresh();
            }).catch(error => {
                title.textContent = before;
                fail(error);
            });
        };
        docRenameCancel.set(box, () => finish(false));
        field.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                finish(true);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                finish(false);
            }
        });
        ok.addEventListener('click', () => finish(true));
    };
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-doc-rename]');
        if (button) {
            startDocRename(button);
        }
    });

    // Long press on a row's name = the pencil (A190): ~2 s with the mouse, ~1 s with a finger; a thin orange line fills
    // under the name meanwhile. Released earlier: nothing, a short click still opens. Moving cancels (a drag starts after
    // a few px instead); the click that ends a long press neither opens the row nor starts a drag.
    const pressTarget = event => {
        const row = event.target.closest?.('[data-folder-row], [data-doc]');
        const name = row?.querySelector('[data-folder-name] strong, [data-doc-title]');
        const trigger = row?.querySelector('[data-folder-rename], [data-doc-rename]');
        if (!name || !trigger || name.closest('[hidden]') || row.classList.contains('is-renaming')) {
            return null;
        }
        const hit = [...name.getClientRects()].some(r => event.clientX >= r.left - 4 && event.clientX <= r.right + 4 && event.clientY >= r.top - 4 && event.clientY <= r.bottom + 4);
        return hit ? { name, trigger } : null;
    };
    let pressedAt = 0;
    document.addEventListener('pointerdown', () => { pressedAt = 0; }, true); // only the press's own click is swallowed
    document.addEventListener('pointerdown', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }
        const hit = pressTarget(event);
        if (!hit) {
            return;
        }
        const touch = event.pointerType !== 'mouse';
        const start = { x: event.clientX, y: event.clientY };
        let timer = 0;
        const move = e => {
            if (Math.hypot(e.clientX - start.x, e.clientY - start.y) > (touch ? 8 : 5)) {
                end();
            }
        };
        const end = () => {
            clearTimeout(timer);
            hit.name.classList.remove('is-pressing', 'is-touch');
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', end);
            window.removeEventListener('pointercancel', end);
        };
        timer = setTimeout(() => {
            end();
            hit.trigger.click();
            pressedAt = Date.now();
        }, touch ? 1000 : 2000);
        hit.name.classList.add('is-pressing');
        hit.name.classList.toggle('is-touch', touch);
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', end);
        window.addEventListener('pointercancel', end);
    });
    document.addEventListener('click', event => {
        if (pressedAt && Date.now() - pressedAt < 1500) {
            pressedAt = 0;
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);
    document.addEventListener('contextmenu', event => {
        if (document.querySelector('.is-pressing') || (pressedAt && Date.now() - pressedAt < 1500)) {
            event.preventDefault();
        }
    });

    // --- Properties modules save on change (A191-A194, FRAMEWORK.md "Properties module") --------------------------
    // form[data-autosave]: every choice (select, radio, checkbox, colour) saves at once. A text field with a ✓ inside
    // (.field-addon with [data-field-save]) saves only on Enter or ✓; the ✓ shows while the value differs, leaving the
    // field keeps text and ✓, Escape restores. Until then such a field is sent with its saved value. The answer's message
    // becomes the toast; DocHamster.on('properties:saved', ({ form, json }) => …) lets the module update other windows.
    const savedByButton = el => el.closest?.('.field-addon')?.querySelector('[data-field-save]') ?? null;
    const markPending = input => {
        const save = savedByButton(input);
        if (save) save.hidden = input.value === input.defaultValue;
    };
    const autosave = (form, field = null) => {
        const body = new FormData(form);
        form.querySelectorAll('.field-addon :is(input, textarea)').forEach(input => {
            if (input !== field && input.name && savedByButton(input)) body.set(input.name, input.defaultValue);
        });
        return post(form.action, body).then(json => {
            if (field) {
                field.defaultValue = field.value;
                markPending(field);
                field.closest('.field-addon').classList.remove('is-error');
                field.removeAttribute('aria-invalid');
            }
            toast(json.message);
            DocHamster.emit('properties:saved', { form, json });
        }).catch(error => {
            if (field) {
                field.closest('.field-addon').classList.add('is-error');
                field.setAttribute('aria-invalid', 'true');
            }
            fail(error);
        });
    };
    document.addEventListener('change', event => {
        const el = event.target;
        const form = el.closest?.('form[data-autosave]');
        if (!form || (!el.name && !el.matches('[data-color-picker]')) || savedByButton(el)) {
            return;
        }
        if (el.matches('[data-color-hex]') && el.value !== '' && !/^#[0-9a-f]{6}$/i.test(el.value)) {
            return; // not a colour yet
        }
        autosave(form);
    });
    document.addEventListener('input', event => {
        if (event.target.closest?.('form[data-autosave] .field-addon')) {
            markPending(event.target);
            event.target.closest('.field-addon').classList.remove('is-error');
        }
    });
    document.addEventListener('keydown', event => {
        const input = event.target;
        if (!input.closest?.('form[data-autosave]') || !savedByButton(input) || !input.matches('input, textarea')) {
            return;
        }
        if (event.key === 'Enter' && input.matches('input')) {
            event.preventDefault();
            if (input.value !== input.defaultValue) autosave(input.form, input);
        } else if (event.key === 'Escape' && input.value !== input.defaultValue) {
            event.preventDefault();
            event.stopPropagation();
            input.value = input.defaultValue;
            markPending(input);
            input.closest('.field-addon').classList.remove('is-error');
            input.removeAttribute('aria-invalid');
        }
    });
    document.addEventListener('click', event => {
        const save = event.target.closest('form[data-autosave] [data-field-save]');
        const input = save?.closest('.field-addon')?.querySelector('input, textarea');
        if (input) {
            event.preventDefault();
            autosave(save.form, input);
        }
    });
    document.addEventListener('submit', event => {
        if (event.target.matches('form[data-autosave]')) {
            event.preventDefault();
            autosave(event.target);
        }
    });

    // --- Field with symbol ("Feld mit Symbol", A195-A199): X clears, the eye shows the password ---------------------
    document.addEventListener('click', event => {
        const clear = event.target.closest('.field-addon [data-field-clear]');
        const reveal = event.target.closest('.field-addon [data-field-reveal]');
        const input = (clear ?? reveal)?.closest('.field-addon').querySelector('input');
        if (!input) {
            return;
        }
        if (clear) {
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        } else {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            reveal.setAttribute('aria-pressed', String(show));
            const label = show ? reveal.dataset.labelHide : reveal.dataset.labelShow;
            if (label) {
                reveal.setAttribute('aria-label', label);
                reveal.dataset.tooltip = label;
            }
        }
    });
    document.addEventListener('input', event => {
        const clear = event.target.closest?.('.field-addon')?.querySelector('[data-field-clear]');
        if (clear) clear.hidden = event.target.value === '';
    });

    // --- Forms of the Papierkorb (restore, delete for good, "Papierkorb leeren"): asked first, sent in the background ----
    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-bulk-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const send = () => post(form.action, new FormData(form)).then(json => {
            form.closest('[data-dialog]')?.querySelector('[data-dialog-close]')?.click();
            toast(json.message);
            refresh();
        }).catch(fail);
        if (form.dataset.confirm) {
            confirmDialog({ title: form.dataset.confirmTitle ?? '', text: form.dataset.confirm, ok: form.dataset.confirmOk ?? '' }).then(yes => yes && send());
        } else {
            send();
        }
    });

    // --- Picking several document rows (A132) ------------------------------------------------------------------
    const scopeOf = el => el.closest('section[data-window]') ?? document;
    const docsIn = scope => [...scope.querySelectorAll('[data-doc]')].filter(row => !row.hidden);
    const anchors = new WeakMap();
    const clear = scope => scope.querySelectorAll('[data-doc].is-selected').forEach(row => {
        row.classList.remove('is-selected');
        row.removeAttribute('aria-selected');
    });
    const pick = (row, on = true) => {
        row.classList.toggle('is-selected', on);
        if (on) {
            row.setAttribute('aria-selected', 'true');
        } else {
            row.removeAttribute('aria-selected');
        }
    };
    let swallowClick = false;
    document.addEventListener('click', event => {
        if (swallowClick) {
            event.preventDefault();
            event.stopPropagation();
            swallowClick = false;
            return;
        }
        const row = event.target.closest?.('[data-doc]');
        if (!row || event.target.closest('.row-actions, input, button, form, .dropdown')) {
            if (!event.target.closest?.('.row-actions, .toast, .dialog')) {
                document.querySelectorAll('[data-doc].is-selected').forEach(other => pick(other, false));
            }
            return;
        }
        const scope = scopeOf(row);
        if (event.ctrlKey || event.metaKey) {
            event.preventDefault();
            event.stopPropagation();
            pick(row, !row.classList.contains('is-selected'));
            anchors.set(scope, row);
        } else if (event.shiftKey) {
            event.preventDefault();
            event.stopPropagation();
            const rows = docsIn(scope);
            const to = rows.indexOf(row);
            const from = rows.includes(anchors.get(scope)) ? rows.indexOf(anchors.get(scope)) : to;
            clear(scope);
            rows.slice(Math.min(from, to), Math.max(from, to) + 1).forEach(r => pick(r));
        } else {
            clear(scope);
            anchors.set(scope, row);
        }
    }, true);
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !event.defaultPrevented && !html.classList.contains('is-carrying')) {
            document.querySelectorAll('[data-doc].is-selected').forEach(row => pick(row, false));
        }
    });

    // --- Dragging documents and folders --------------------------------------------------------------------------
    const winOf = el => el?.closest?.('[data-windows] > section[data-window], section[data-window]') ?? null;
    const folderOfWindow = win => new URLSearchParams(win?.dataset.windowUpload ?? '').get('folder');
    // The one row or window body a drop would land on: orange (is-drop-target); for files also is-upload-target, which
    // turns a folder row into the field "Loslassen zum Hochladen in „X“" (A170, A175).
    const setTarget = (() => {
        let current = null;
        return (el, upload = false) => {
            if (current === el && current?.classList.contains('is-upload-target') === upload) {
                return;
            }
            current?.classList.remove('is-drop-target', 'is-upload-target');
            current = el;
            current?.classList.add('is-drop-target');
            current?.classList.toggle('is-upload-target', upload);
        };
    })();

    // What lies under the pointer, and whether the carried rows may land there.
    const aim = (carry, x, y) => {
        const el = document.elementFromPoint(x, y);
        if (!el || el.closest('.drag-ghost')) {
            return null;
        }
        const cfg = config();
        const row = el.closest('[data-folder-row]');
        const list = el.closest('.folder-rows');
        if (carry.kind === 'folder') {
            const source = carry.items[0];
            // Same list: the upper and lower quarter of a row (and the gaps) are a new place, the middle goes into it (A153).
            if (list && list === source.parentElement && list.dataset.folderOrder) {
                const rows = [...list.querySelectorAll(':scope > [data-folder-row]')];
                const hit = row && rows.includes(row) ? row : null;
                const box = hit?.getBoundingClientRect();
                const into = hit && box && y > box.top + box.height / 4 && y < box.bottom - box.height / 4;
                if (!into) {
                    const before = rows.find(r => y < r.getBoundingClientRect().top + r.offsetHeight / 2) ?? null;
                    const next = source.nextElementSibling?.matches('[data-folder-row]') ? source.nextElementSibling : null;
                    if (before === source || before === next) {
                        return null; // stays where it is
                    }
                    return { type: 'order', list, before };
                }
            }
            if (!row || row === source) {
                return null;
            }
            if (row.hasAttribute('data-drop-trash')) {
                return cfg.folderDestroy ? { type: 'folder-trash', el: row } : null;
            }
            if (!cfg.folderMove || (row.dataset.path ?? '').split(' ').includes(source.dataset.id) || row.dataset.id === source.dataset.parentId) {
                return null; // into itself, below itself (A138) or where it is already
            }
            if (Number(row.dataset.depth) + 1 + Number(source.dataset.height) > Number(cfg.maxDepth)) {
                return { type: 'too-deep' }; // no highlight, a hint on drop (A139)
            }
            return { type: 'folder-into', el: row, folder: row.dataset.id };
        }
        // Documents.
        if (row) {
            if (row.hasAttribute('data-drop-trash')) {
                return cfg.trash && !carry.trashed ? { type: 'trash', el: row } : null;
            }
            return cfg.move && !carry.items.every(item => item.dataset.folder === row.dataset.id && !item.hasAttribute('data-trashed'))
                ? { type: 'move', el: row, folder: row.dataset.id } : null;
        }
        const win = winOf(el);
        const body = el.closest('.win-body');
        if (!win || !body || win.classList.contains('is-min')) {
            return null;
        }
        if (win.dataset.windowKind === 'trash') {
            return cfg.trash && !carry.trashed ? { type: 'trash', el: body } : null;
        }
        const folder = folderOfWindow(win);
        if (win.dataset.windowKind === 'folder' && folder && cfg.move && !body.querySelector('[data-no-drop]') && !carry.items.every(item => item.dataset.folder === folder && !item.hasAttribute('data-trashed'))) {
            return { type: 'move', el: body, folder };
        }
        return null;
    };

    const undoMoves = moves => {
        const cfg = config();
        const groups = new Map();
        moves.filter(m => m.folder).forEach(m => groups.set(m.folder, [...(groups.get(m.folder) ?? []), m.id]));
        const back = [...groups].reduce((chain, [folder, ids]) => chain.then(() => post(cfg.move, { ids, folder })), Promise.resolve());
        const trashed = moves.filter(m => m.trashed).map(m => m.id);
        back.then(() => (trashed.length && cfg.trash ? post(cfg.trash, { ids: trashed }) : null))
            .then(() => {
                toast(cfg.undone);
                refresh();
            })
            .catch(fail);
    };

    const land = (carry, target) => {
        const cfg = config();
        const ids = carry.items.map(item => item.dataset.doc ?? item.dataset.id);
        if (target.type === 'move') {
            post(cfg.move, { ids, folder: target.folder }).then(json => {
                toast(json.message, { undo: () => undoMoves(json.moves ?? []) });
                refresh();
            }).catch(fail);
        } else if (target.type === 'trash') {
            post(cfg.trash, { ids }).then(json => {
                toast(json.message, { undo: () => post(cfg.restore, { ids: json.ids ?? ids }).then(() => { toast(cfg.undone); refresh(); }).catch(fail) });
                refresh();
            }).catch(fail);
        } else if (target.type === 'folder-into') {
            const source = carry.items[0];
            const url = cfg.folderMove.replace('__ID__', source.dataset.id);
            const from = source.dataset.parentId ?? '';
            post(url, { parent_id: target.folder }).then(json => {
                toast(json.message, { undo: () => post(url, { parent_id: from }).then(() => { toast(cfg.undone); refresh(); }).catch(fail) });
                refresh();
            }).catch(fail);
        } else if (target.type === 'folder-trash') {
            const source = carry.items[0];
            confirmDialog({ title: cfg.folderTrashTitle, text: fill(cfg.folderTrashText, { name: source.dataset.name }), ok: cfg.folderTrashOk }).then(yes => {
                if (yes) {
                    post(cfg.folderDestroy.replace('__ID__', source.dataset.id), { with_content: 1 }).then(json => {
                        toast(json.message);
                        refresh();
                    }).catch(fail);
                }
            });
        } else if (target.type === 'order') {
            const source = carry.items[0];
            const list = target.list;
            const from = source.getBoundingClientRect();
            list.insertBefore(source, target.before);
            if (!still.matches) {
                const to = source.getBoundingClientRect();
                source.animate([{ transform: `translate(${from.left - to.left}px, ${from.top - to.top}px)` }, { transform: 'none' }], { duration: 180, easing: 'ease-out' });
            }
            const order = [...list.querySelectorAll(':scope > [data-folder-row]')].map(r => r.dataset.id);
            post(list.dataset.folderOrder, { order, parent_id: list.dataset.parent || null }).catch(fail);
        } else if (target.type === 'too-deep') {
            toast(cfg.tooDeep, { error: true });
        }
    };

    const carryOut = (source, start) => {
        let items;
        let kind;
        if (source.matches('[data-doc]')) {
            const scope = scopeOf(source);
            if (!source.classList.contains('is-selected')) {
                document.querySelectorAll('[data-doc].is-selected').forEach(row => pick(row, false));
                pick(source);
            }
            items = docsIn(scope).filter(row => row.classList.contains('is-selected'));
            kind = 'docs';
        } else {
            items = [source];
            kind = 'folder';
        }
        const carry = { kind, items, trashed: items.some(item => item.hasAttribute('data-trashed')) };
        items.forEach(item => item.classList.add('is-carried'));

        const ghost = document.createElement('div');
        ghost.className = 'drag-ghost';
        ghost.setAttribute('aria-hidden', 'true');
        ghost.textContent = kind === 'folder' ? source.dataset.name : (items.length === 1 ? (items[0].querySelector('[data-doc-title]')?.textContent.trim() ?? docsLabel(1)) : docsLabel(items.length));
        document.body.append(ghost);
        html.classList.add('is-carrying');

        let bar = null;
        let target = null;
        let pointer = { x: start.clientX, y: start.clientY };
        let frame = 0;
        const place = () => {
            ghost.style.transform = `translate(${pointer.x + 14}px, ${pointer.y + 10}px)`;
            target = aim(carry, pointer.x, pointer.y);
            setTarget(target?.el ?? null);
            if (target?.type === 'order') {
                if (!bar || bar.parentElement !== target.list) {
                    bar?.remove();
                    bar = document.createElement('div');
                    bar.className = 'pick-drop';
                    target.list.append(bar);
                }
                const rows = [...target.list.querySelectorAll(':scope > [data-folder-row]')];
                const top = target.list.getBoundingClientRect().top;
                const gap = parseFloat(getComputedStyle(target.list).rowGap) || 0;
                const y = target.before ? target.before.getBoundingClientRect().top - gap / 2 : rows.at(-1).getBoundingClientRect().bottom + gap / 2;
                bar.style.top = `${y - top}px`;
            } else {
                bar?.remove();
                bar = null;
            }
        };
        // Near the top or bottom edge of a window body the body scrolls along.
        const edge = () => {
            const body = document.elementFromPoint(pointer.x, pointer.y)?.closest('.win-body');
            if (body) {
                const rect = body.getBoundingClientRect();
                const zone = 40;
                const speed = pointer.y < rect.top + zone ? pointer.y - (rect.top + zone) : pointer.y > rect.bottom - zone ? pointer.y - (rect.bottom - zone) : 0;
                if (speed) {
                    body.scrollTop += speed / 3;
                    place();
                }
            }
            frame = requestAnimationFrame(edge);
        };
        const move = event => {
            pointer = { x: event.clientX, y: event.clientY };
            place();
        };
        const finish = drop => {
            cancelAnimationFrame(frame);
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('pointercancel', cancel);
            window.removeEventListener('keydown', key, true);
            dragging = false;
            html.classList.remove('is-carrying');
            ghost.remove();
            bar?.remove();
            setTarget(null);
            items.forEach(item => item.classList.remove('is-carried'));
            // The release must not also open the row's link.
            swallowClick = true;
            setTimeout(() => { swallowClick = false; }, 0);
            if (drop && target) {
                land(carry, target);
            }
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
        dragging = true;
        place();
        frame = requestAnimationFrame(edge);
    };

    let dragging = false;
    document.addEventListener('touchmove', event => {
        if (dragging) {
            event.preventDefault();
        }
    }, { passive: false });
    document.addEventListener('dragstart', event => {
        if (event.target.closest?.('[data-doc], [data-drag-folder]')) {
            event.preventDefault();
        }
    });
    document.addEventListener('pointerdown', event => {
        const cfg = config();
        const source = event.target.closest?.('[data-doc], [data-drag-folder]');
        if (!source || event.button !== 0 || event.target.closest('input, select, textarea, button, form, .row-actions, .dropdown, .doc-rename')) {
            return;
        }
        if (source.matches('[data-doc]') && !cfg.move && !cfg.trash) {
            return;
        }
        if (source.matches('[data-doc][data-trashed]') && !cfg.move) {
            return;
        }
        const touch = event.pointerType === 'touch';
        const start = { clientX: event.clientX, clientY: event.clientY };
        if (!touch) {
            event.preventDefault(); // no text selection while dragging
        }
        let timer = 0;
        const stop = () => {
            clearTimeout(timer);
            window.removeEventListener('pointermove', watch);
            window.removeEventListener('pointerup', stop);
            window.removeEventListener('pointercancel', stop);
        };
        const begin = () => {
            stop();
            if (source.classList.contains('is-renaming')) {
                return; // a long press opened the rename (A190): no drag from it
            }
            carryOut(source, start);
        };
        const watch = e => {
            const far = Math.hypot(e.clientX - start.clientX, e.clientY - start.clientY);
            if (touch) {
                if (far > 8) {
                    stop(); // the finger scrolls
                }
            } else if (far > 5) {
                begin(); // a few pixels first (A155): a click stays a click
            }
        };
        window.addEventListener('pointermove', watch);
        window.addEventListener('pointerup', stop);
        window.addEventListener('pointercancel', stop);
        if (touch && !pressTarget(event)) {
            timer = setTimeout(begin, 350); // a finger on the name waits for the long press instead (A190)
        }
    });

    // --- Files from the computer (point 85, A169-A175) -------------------------------------------------------------
    const carriesFiles = event => event.dataTransfer !== null && [...(event.dataTransfer?.types ?? [])].includes('Files');
    const navDrop = () => document.querySelector('[data-nav-drop]');
    const fileTarget = el => {
        const cfg = config();
        if (!cfg.upload || !(el instanceof Element)) {
            return null;
        }
        if (el.closest('.side')) {
            const field = navDrop();
            return field ? { el: field, folder: cfg.defaultFolder, name: cfg.defaultName } : null;
        }
        const row = el.closest('[data-folder-row]');
        if (row) {
            return row.hasAttribute('data-drop-trash') ? null : { el: row, folder: row.dataset.id, name: row.dataset.name };
        }
        const win = winOf(el);
        const body = el.closest('.win-body');
        if (win && body && win.dataset.windowKind === 'folder' && !win.classList.contains('is-min') && !body.querySelector('[data-no-drop]')) {
            const folder = folderOfWindow(win);
            const name = folder ? win.dataset.windowTitle : cfg.defaultName;
            return { el: body, folder: folder ?? cfg.defaultFolder, name };
        }
        return null;
    };
    // A folder window's body shows the same words as a folder row: "Loslassen zum Hochladen in „X“" (A170, A175).
    const uploadLabel = target => {
        if (target.el.matches('.win-body')) {
            target.el.dataset.uploadLabel = fill(config().dropUpload, { name: target.name });
        }
    };
    let fileDepth = 0;
    const fileEnd = () => {
        fileDepth = 0;
        html.classList.remove('is-file-drag');
        const field = navDrop();
        if (field) {
            field.hidden = true;
        }
        setTarget(null);
    };
    document.addEventListener('dragenter', event => {
        if (!carriesFiles(event) || !config().upload) {
            return;
        }
        fileDepth++;
        html.classList.add('is-file-drag');
        const field = navDrop();
        if (field) {
            field.hidden = false;
        }
    });
    document.addEventListener('dragleave', event => {
        if (carriesFiles(event) && --fileDepth <= 0) {
            fileEnd();
        }
    });
    document.addEventListener('dragover', event => {
        if (!carriesFiles(event)) {
            return;
        }
        event.preventDefault(); // never let the browser open the file itself
        const target = fileTarget(event.target);
        event.dataTransfer.dropEffect = target ? 'copy' : 'none';
        if (target) {
            uploadLabel(target);
        }
        setTarget(target?.el ?? null, true);
    });
    document.addEventListener('drop', event => {
        if (!carriesFiles(event) && [...(event.dataTransfer?.types ?? [])].includes('application/x-dochamster-preview')) {
            event.preventDefault();
            return;
        }
        if (!carriesFiles(event)) {
            return;
        }
        event.preventDefault();
        const target = fileTarget(event.target);
        const files = [...event.dataTransfer.files];
        fileEnd();
        if (!target || files.length === 0) {
            return;
        }
        upload(files, target);
    });
    const upload = (files, target) => {
        const cfg = config();
        const chunks = [];
        for (let i = 0; i < files.length; i += 20) {
            chunks.push(files.slice(i, i + 20));
        }
        const busy = document.querySelector('.nav-upload');
        busy?.classList.add('is-busy');
        let saved = 0;
        chunks.reduce((chain, chunk) => chain.then(() => {
            const body = new FormData();
            chunk.forEach(file => body.append('files[]', file));
            body.append('folder', target.folder);
            return post(cfg.upload, body).then(() => { saved += chunk.length; });
        }), Promise.resolve())
            .then(() => {
                toast(fill(saved === 1 ? cfg.uploadedOne : cfg.uploadedMany, { count: saved, name: target.name }));
            })
            .catch(fail)
            .finally(() => {
                busy?.classList.remove('is-busy');
                refresh();
            });
    };
})();

// Update page: the install runs in a pop-up with a progress bar instead of on install.php's own page.
// Without JavaScript, and whenever something fails before the run starts, the browser goes the old way
// (form post, then install.php's own pages). The bar follows install.php's progress file, not
// install.php itself (that request holds the installer's session for the whole run).
(() => { const setupUpdateProgress = () => {
    const box = document.querySelector('[data-update-progress]');
    if (!box || !window.fetch) {
        return;
    }
    const steps = JSON.parse(box.dataset.steps || '{}');
    const bar = box.querySelector('[data-progress-bar]');
    const stepText = box.querySelector('[data-progress-step]');
    const errorText = box.querySelector('[data-progress-error]');
    const actions = box.querySelector('[data-progress-actions]');
    let percent = 0;
    const setStep = (key, value) => {
        if (steps[key]) {
            stepText.textContent = steps[key];
        }
        // Never backwards: the download's first answer is GitHub's redirect.
        if (value > percent) {
            percent = Math.min(100, value);
            bar.value = percent;
        }
    };
    const retry = box.querySelector('[data-progress-retry]');
    let retryForm = null;
    const fail = (message, form = null) => {
        errorText.textContent = message;
        errorText.hidden = false;
        actions.hidden = false;
        retryForm = form;
        if (retry) retry.hidden = form === null;
        (form && retry ? retry : actions.querySelector('[data-progress-close]')).focus();
    };
    box.querySelector('[data-progress-close]').addEventListener('click', () => { box.hidden = true; });
    // A87: the first request failed – try again from the pop-up instead of the old error page.
    retry?.addEventListener('click', () => {
        const form = retryForm;
        box.hidden = true;
        if (form) form.requestSubmit();
    });

    const follow = () => {
        const until = Date.now() + 5 * 60 * 1000;
        let answeredAt = 0;
        let over = false;
        const finish = url => {
            over = true;
            setStep('done', 100);
            window.location.href = url;
        };
        // The run takes about a minute; its answer may also never come (proxy timeout) – the file decides.
        fetch('/install.php?run=1', { credentials: 'same-origin' })
            .then(response => {
                if (response.redirected && new URL(response.url).pathname === '/update') {
                    finish(response.url);
                } else if (response.ok) {
                    answeredAt = Date.now();
                }
            })
            .catch(() => { /* keep following the file */ });
        const tick = () => {
            if (over) {
                return;
            }
            fetch(`/install-status.json?t=${Date.now()}`, { credentials: 'same-origin', cache: 'no-store' })
                .then(response => (response.ok ? response.json() : null))
                .catch(() => null)
                .then(status => {
                    if (over) {
                        return;
                    }
                    if (status && status.state === 'done') {
                        finish('/update?updated=1');
                        return;
                    }
                    if (status && status.state === 'error') {
                        over = true;
                        fail(status.message || box.dataset.failed);
                        return;
                    }
                    if (status) {
                        setStep(status.step, Number(status.percent) || 0);
                    }
                    if ((answeredAt && Date.now() - answeredAt > 5000) || Date.now() > until) {
                        over = true;
                        fail(answeredAt ? box.dataset.failed : box.dataset.timeout);
                        return;
                    }
                    setTimeout(tick, 700);
                });
        };
        tick();
    };

    const showUnreachable = (form, status) => {
        document.querySelectorAll('[data-dialog]').forEach(dialog => { dialog.hidden = true; });
        box.querySelector('[data-progress-title]').textContent = box.dataset.title.replace(':version', '');
        percent = 0;
        bar.value = 0;
        stepText.textContent = '';
        box.hidden = false;
        fail((box.dataset.unreachable || box.dataset.failed).replace(':status', status ? String(status) : '–'), form);
    };
    document.querySelectorAll('form[data-update-install]').forEach(form => form.addEventListener('submit', event => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        button.classList.add('is-busy');
        const oldWay = () => form.submit();
        fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => (response.ok ? response.json() : Promise.reject(response)))
            .then(data => {
                if (!data || typeof data.url !== 'string') {
                    oldWay();
                    return;
                }
                // An installer without a progress file: its own pages, as before.
                if (!data.progress) {
                    window.location.href = data.url;
                    return;
                }
                document.querySelectorAll('[data-dialog]').forEach(dialog => { dialog.hidden = true; });
                box.querySelector('[data-progress-title]').textContent = box.dataset.title.replace(':version', data.version || '');
                percent = 0;
                bar.value = 0;
                errorText.hidden = true;
                actions.hidden = true;
                setStep('start', 0);
                box.hidden = false;
                // The code opens the installer's session; only its "is being updated" page leads on to the run.
                fetch(data.url, { credentials: 'same-origin' })
                    .then(response => (response.ok ? response.text() : ''))
                    .catch(() => '')
                    .then(page => {
                        if (page.includes('?run=1')) {
                            follow();
                        } else {
                            window.location.href = data.url;
                        }
                    });
            })
            .catch(error => {
                // A wrong password (422) or a redirect with a message: the page shows it, as before.
                if (!(error instanceof Response) || error.status === 422) {
                    if (error instanceof Response) oldWay(); else showUnreachable(form, 0);
                    return;
                }
                showUnreachable(form, error.status);
            })
            .finally(() => {
                button.disabled = false;
                button.classList.remove('is-busy');
            });
    }));
}; if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setupUpdateProgress); else setupUpdateProgress();
})();

// -------------------------------------------------------------------------------------------------
// Text documents (point 21): "Neues Dokument" creates one and opens its editor as a window; the
// editor saves with its button or Ctrl+S in the background and shows whether something is unsaved.
// Formatted text: contenteditable with a small toolbar. Size, font, alignment and indent become
// classes (rt-*), because the CSP forbids inline styles; the server sanitizes again (RichText).
// -------------------------------------------------------------------------------------------------
(() => {
    const DocHamster = window.DocHamster;
    const json = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    // Create: the editor opens as a window right of the folder (window system), else as a page.
    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-text-create]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: json })
            .then(response => (response.ok ? response.json() : Promise.reject(response)))
            .then(data => {
                form.closest('[data-dialog]')?.setAttribute('hidden', '');
                form.reset();
                const win = form.closest('[data-windows] > section[data-window]');
                if (!win) {
                    window.location.href = data.url;
                    return;
                }
                const link = document.createElement('a');
                link.href = data.url;
                link.dataset.window = '';
                link.hidden = true;
                win.append(link);
                link.click();
                link.remove();
            })
            .catch(() => form.submit())
            .finally(() => { button.disabled = false; });
    });
    document.addEventListener('click', event => {
        const opener = event.target.closest('[data-dialog-open^="new-text-"]');
        if (opener) {
            const scope = opener.closest('section[data-window]') ?? document;
            setTimeout(() => scope.querySelector(`[data-dialog="${opener.dataset.dialogOpen}"] input[name="name"]`)?.focus());
        }
    });

    const BLOCK = 'p, div, li';
    const FACES = { sans: 'sans-serif', serif: 'serif', mono: 'monospace' };
    const fontClass = face => {
        const f = (face || '').toLowerCase();
        if (f.includes('mono') || f.includes('courier')) {
            return 'rt-font-mono';
        }
        if (f.includes('sans') || f.includes('arial') || f.includes('helvetica')) {
            return 'rt-font-sans';
        }
        if (f.includes('serif') || f.includes('times') || f.includes('georgia')) {
            return 'rt-font-serif';
        }
        return null;
    };

    const setupRich = (form, area, changed) => {
        const toolbar = form.querySelector('.rt-toolbar');
        const exec = (command, value = null) => {
            area.focus();
            document.execCommand('styleWithCSS', false, false);
            document.execCommand('defaultParagraphSeparator', false, 'p');
            document.execCommand(command, false, value);
        };
        // Loose text at the top level gets a paragraph, so alignment and indent have a block.
        const wrapLoose = () => {
            let run = null;
            [...area.childNodes].forEach(node => {
                const loose = node.nodeType === Node.TEXT_NODE
                    ? (node.textContent.trim() !== '' || run !== null)
                    : node.nodeType === Node.ELEMENT_NODE && !node.matches('p, div, ul, ol');
                if (!loose) {
                    run = null;
                    return;
                }
                if (!run) {
                    run = document.createElement('p');
                    node.before(run);
                }
                run.append(node);
            });
            if (!area.firstChild) {
                area.innerHTML = '<p><br></p>';
            }
        };
        wrapLoose();

        const blockOf = node => {
            let el = node?.nodeType === Node.ELEMENT_NODE ? node : node?.parentElement;
            while (el && el !== area && !el.matches(BLOCK)) {
                el = el.parentElement;
            }
            return el && el !== area && area.contains(el) ? el : null;
        };
        const range = () => {
            const sel = getSelection();
            return sel.rangeCount && area.contains(sel.getRangeAt(0).commonAncestorContainer) ? sel.getRangeAt(0) : null;
        };
        const blocks = () => {
            let r = range();
            if (!r) {
                return [];
            }
            const nodes = () => {
                const out = [r.startContainer, r.endContainer];
                const walker = document.createTreeWalker(area, NodeFilter.SHOW_TEXT);
                while (walker.nextNode()) {
                    if (r.intersectsNode(walker.currentNode)) {
                        out.push(walker.currentNode);
                    }
                }
                return out;
            };
            if (nodes().some(node => node !== area && !blockOf(node))) {
                exec('formatBlock', 'p');
                r = range();
                if (!r) {
                    return [];
                }
            }
            return [...new Set(nodes().map(blockOf).filter(Boolean))];
        };
        const setClass = (el, prefix, value) => {
            [...el.classList].filter(name => name.startsWith(prefix)).forEach(name => el.classList.remove(name));
            if (value) {
                el.classList.add(value);
            }
        };
        const indentOf = el => Number([...el.classList].find(name => name.startsWith('rt-indent-'))?.slice(10) ?? 0);

        // The browser writes <font size|face>; we turn it into a span with our class and keep the selection.
        const fontsToClasses = () => {
            const spans = [...area.querySelectorAll('font')].map(font => {
                const span = document.createElement('span');
                const size = Number(font.getAttribute('size'));
                if (size >= 1 && size <= 7) {
                    span.classList.add(`rt-size-${size}`);
                }
                const face = fontClass(font.getAttribute('face'));
                if (face) {
                    span.classList.add(face);
                }
                span.append(...font.childNodes);
                font.replaceWith(span);
                return span;
            });
            if (spans.length) {
                const r = document.createRange();
                r.setStartBefore(spans[0]);
                r.setEndAfter(spans.at(-1));
                getSelection().removeAllRanges();
                getSelection().addRange(r);
            }
        };

        // Pressed buttons follow the text at the caret.
        const marks = () => {
            const r = range();
            if (!r) {
                return;
            }
            toolbar.querySelectorAll('[data-rt="bold"], [data-rt="italic"], [data-rt="underline"]').forEach(button => {
                button.setAttribute('aria-pressed', String(document.queryCommandState(button.dataset.rt)));
            });
            const block = blockOf(r.startContainer);
            const align = [...(block?.classList ?? [])].find(name => name.startsWith('rt-align-'))?.slice(9) ?? 'left';
            toolbar.querySelectorAll('[data-rt="align"]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.rtValue === align)));
        };

        const run = (command, value) => {
            switch (command) {
                case 'bold':
                case 'italic':
                case 'underline':
                    exec(command);
                    break;
                case 'bullets':
                    exec('insertUnorderedList');
                    break;
                case 'numbers':
                    exec('insertOrderedList');
                    break;
                case 'indent':
                case 'outdent': {
                    const found = blocks();
                    if (found.some(el => el.matches('li'))) {
                        exec(command);
                        break;
                    }
                    found.forEach(el => {
                        const level = Math.min(8, Math.max(0, indentOf(el) + (command === 'indent' ? 1 : -1)));
                        setClass(el, 'rt-indent-', level ? `rt-indent-${level}` : null);
                    });
                    break;
                }
                case 'align':
                    blocks().forEach(el => setClass(el, 'rt-align-', value === 'left' ? null : `rt-align-${value}`));
                    break;
                case 'font':
                    exec('fontName', FACES[value] ?? value);
                    fontsToClasses();
                    break;
                case 'size':
                    exec('fontSize', value);
                    fontsToClasses();
                    break;
                default:
                    return;
            }
            area.querySelectorAll('[style]').forEach(el => el.removeAttribute('style'));
            changed();
            marks();
        };

        document.addEventListener('selectionchange', marks);
        DocHamster.on('window:close', root => {
            if (root.contains(form)) {
                document.removeEventListener('selectionchange', marks);
            }
        });

        // A click on the toolbar keeps the selection in the text.
        toolbar.addEventListener('mousedown', event => {
            if (event.target.closest('button')) {
                event.preventDefault();
            }
        });
        toolbar.addEventListener('click', event => {
            const button = event.target.closest('button[data-rt]');
            if (button) {
                run(button.dataset.rt, button.dataset.rtValue);
            }
        });
        // The selection is lost while a select is open; it is put back before the command.
        let kept = null;
        toolbar.addEventListener('pointerdown', event => {
            if (event.target.closest('select')) {
                kept = range()?.cloneRange() ?? kept;
            }
        });
        toolbar.addEventListener('change', event => {
            const select = event.target.closest('select');
            if (!select || select.value === '') {
                return;
            }
            area.focus();
            if (kept) {
                getSelection().removeAllRanges();
                getSelection().addRange(kept);
            }
            run(select.matches('[data-rt-font]') ? 'font' : 'size', select.value);
            select.value = '';
        });
        // Pasted text comes in as plain text: no foreign styles, images or links.
        area.addEventListener('paste', event => {
            event.preventDefault();
            document.execCommand('insertText', false, event.clipboardData?.getData('text/plain') ?? '');
        });
        area.addEventListener('drop', event => event.preventDefault());

        return () => area.innerHTML;
    };

    const dirty = new Set();
    const setupEditor = form => {
        if (form.dataset.textReady) {
            return;
        }
        form.dataset.textReady = '1';
        const win = form.closest('section[data-window]') ?? document;
        const state = win.querySelector('[data-text-state]');
        const show = (key, isDirty = false, isError = false) => {
            if (state) {
                state.textContent = key ? state.dataset[key] : '';
                state.classList.toggle('is-dirty', isDirty);
                state.classList.toggle('is-error', isError);
            }
        };
        const rich = form.querySelector('[data-rt-area]');
        const plain = form.querySelector('[data-text-area]');
        let current = () => plain?.value ?? '';
        let saved = '';
        let everSaved = false;
        const changed = () => {
            const isDirty = current() !== saved;
            if (isDirty) {
                dirty.add(form);
            } else {
                dirty.delete(form);
            }
            show(isDirty ? 'unsaved' : (everSaved ? 'saved' : ''), isDirty);
        };
        if (rich) {
            current = setupRich(form, rich, changed);
        }
        saved = current();
        form.addEventListener('input', changed);

        let busy = false;
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (busy) {
                return;
            }
            const sent = current();
            const field = form.querySelector('[data-rt-content]');
            if (field) {
                field.value = sent;
            }
            busy = true;
            show('saving', true);
            fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: json })
                .then(response => (response.ok ? response.json() : Promise.reject(response)))
                .then(() => {
                    saved = sent;
                    everSaved = true;
                    changed();
                })
                .catch(error => {
                    show('failed', true, true);
                    if (error instanceof Response) {
                        error.json().then(data => {
                            if (state && data?.message) {
                                state.textContent = data.message;
                            }
                        }).catch(() => { /* the short message stays */ });
                    }
                })
                .finally(() => { busy = false; });
        });
        form.addEventListener('keydown', event => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                form.requestSubmit();
            }
        });
        // Ctrl+S also while the save button in the window toolbar has the focus.
        if (win !== document) {
            win.querySelector('.win-tools')?.addEventListener('keydown', event => {
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                    event.preventDefault();
                    form.requestSubmit();
                }
            });
        }
        DocHamster.on('window:close', root => {
            if (root.contains(form)) {
                dirty.delete(form);
            }
        });
    };

    DocHamster.on('window:open', root => root.querySelectorAll('form[data-text-editor]').forEach(setupEditor));
    window.addEventListener('beforeunload', event => {
        if ([...dirty].some(form => form.isConnected)) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
})();

/* Package E */
// Click-to-edit (point 28, <x-edit-in-place>): plain text until clicked or Enter, then a field of the same size.
// Blur or Ctrl+Enter saves (POST field + value, JSON answer), Escape restores the old text. Works in windows
// loaded later too (one listener for the page). Also: [data-focus="id"] menu entries focus that field.
(() => {
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const note = (text, failed) => {
        const el = document.createElement('div');
        el.className = failed ? 'toast toast-error' : 'toast';
        el.setAttribute('role', failed ? 'alert' : 'status');
        el.textContent = text;
        document.body.append(el);
        const leave = () => { el.classList.add('is-leaving'); setTimeout(() => el.remove(), 300); };
        el.addEventListener('click', leave);
        setTimeout(leave, failed ? 5000 : 2000);
    };
    const grow = field => {
        if (field.tagName === 'TEXTAREA') {
            field.style.height = 'auto';
            field.style.height = `${field.scrollHeight + 2}px`;
        }
    };
    const finish = (box, field) => {
        field.remove();
        box.hidden = false;
    };
    const save = (box, field) => {
        const old = box.textContent;
        const value = field.value;
        finish(box, field);
        if (value.trim() === old.trim()) {
            return;
        }
        box.textContent = value.trim();
        box.classList.add('is-saving');
        const body = new FormData();
        body.append('field', box.dataset.field);
        body.append('value', value);
        fetch(box.dataset.editInPlace, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
            .then(response => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then(data => {
                box.textContent = typeof data.value === 'string' ? data.value : value.trim();
                note(box.dataset.saved, false);
            })
            .catch(() => {
                box.textContent = old;
                note(box.dataset.failed, true);
            })
            .finally(() => box.classList.remove('is-saving'));
    };
    const start = box => {
        if (box.hidden || box.classList.contains('is-saving')) {
            return;
        }
        const multiline = box.classList.contains('is-multiline');
        const field = document.createElement(multiline ? 'textarea' : 'input');
        field.className = `edit-in-place-field ${box.className.replace(/\bedit-in-place\b/, '')}`.trim();
        field.value = box.textContent;
        field.placeholder = box.dataset.placeholder ?? '';
        if (box.dataset.maxlength) {
            field.maxLength = Number(box.dataset.maxlength);
        }
        const labelledBy = box.getAttribute('aria-labelledby');
        if (labelledBy) {
            field.setAttribute('aria-labelledby', labelledBy);
        }
        const height = box.offsetHeight;
        box.hidden = true;
        box.after(field);
        if (multiline) {
            field.style.minHeight = `${height}px`;
            grow(field);
        }
        field.focus();
        field.addEventListener('input', () => grow(field));
        field.addEventListener('blur', () => { if (field.isConnected && !field.dataset.cancelled) save(box, field); });
        field.addEventListener('keydown', event => {
            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey || !multiline)) {
                event.preventDefault();
                field.blur();
                box.focus();
            }
        });
    };
    document.addEventListener('click', event => {
        const box = event.target.closest('[data-edit-in-place]');
        if (box) {
            start(box);
            return;
        }
        const jump = event.target.closest('[data-focus]');
        const target = jump && document.getElementById(jump.dataset.focus);
        if (target?.matches('[data-edit-in-place]')) {
            event.preventDefault();
            start(target);
        } else if (target) {
            event.preventDefault();
            target.scrollIntoView({ block: 'center', behavior: 'smooth' });
            target.focus({ preventScroll: true });
            target.select?.();
        }
    });
    document.addEventListener('keydown', event => {
        const box = event.target.closest?.('[data-edit-in-place]');
        if (box && event.key === 'Enter') {
            event.preventDefault();
            start(box);
        }
    });
    // Escape first, before windows, columns and dialogs react to the same key (window capture runs before document).
    window.addEventListener('keydown', event => {
        const field = event.target.closest?.('.edit-in-place-field');
        if (field && event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            const box = field.previousElementSibling;
            field.dataset.cancelled = '1';
            finish(box, field);
            box.focus();
        }
    }, { capture: true });
})();
/* Package G */
// Text recognition progress (points 35/43, A21-A24, 57): the OCR indicator below Abmelden polls while documents are open,
// the Texterkennung page every 3 s while something waits or runs; the browser fallback reports its percent.
(() => {
    const getJson = url => fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(response => (response.ok ? response.json() : null))
        .catch(() => null);
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const PAUSE_KEY = 'dh.ocr.paused';
    const isPaused = () => {
        try { return localStorage.getItem(PAUSE_KEY) === '1'; } catch { return false; }
    };

    // Point 57: OCR indicator below Abmelden – open count, small bar, pause per browser; hidden while nothing is open.
    const showNav = data => {
        const nav = document.querySelector('[data-ocr-nav]');
        if (!nav || !data) {
            return;
        }
        const open = data.open ?? data.waiting ?? 0;
        nav.dataset.ocrOpen = String(open);
        const paused = isPaused() && !!nav.querySelector('[data-ocr-toggle]');
        const count = nav.querySelector('[data-ocr-open-text]');
        const pausedLabel = nav.dataset.pausedLabel ?? '';
        if (count) {
            count.textContent = (nav.dataset.label ?? '').replace(':count', String(open)) + (paused ? ` · ${pausedLabel}` : '');
        }
        // A61: why the browser engine does not start.
        if (data.total !== undefined) {
            nav.dataset.engineError = data.engine_error ? (nav.dataset.engineErrorLabel ?? '').replace(':reason', data.engine_error) : '';
        }
        const engineError = nav.dataset.engineError ?? '';
        const icon = nav.querySelector('[data-ocr-icon]');
        if (icon) {
            const tip = (nav.dataset.tooltipLabel ?? '').replace(':count', String(open)) + (paused ? ` · ${pausedLabel}` : '') + (engineError ? ` · ${engineError}` : '');
            icon.dataset.tooltip = tip;
            icon.setAttribute('aria-label', tip);
        }
        const bar = nav.querySelector('[data-ocr-nav-bar]');
        if (bar && data.total !== undefined) {
            bar.max = Math.max(1, (data.total ?? 0) * Number(nav.dataset.passes ?? 3));
            bar.value = data.passes ?? 0;
        }
        nav.hidden = open === 0;
    };

    const paintPause = () => {
        const nav = document.querySelector('[data-ocr-nav]');
        const toggle = nav?.querySelector('[data-ocr-toggle]');
        if (!toggle) {
            return;
        }
        const paused = isPaused();
        nav.classList.toggle('is-paused', paused);
        const label = paused ? toggle.dataset.resume : toggle.dataset.pause;
        toggle.dataset.tooltip = label ?? '';
        toggle.setAttribute('aria-label', label ?? '');
        toggle.setAttribute('aria-pressed', paused ? 'true' : 'false');
        showNav({ open: Number(nav.dataset.ocrOpen ?? 0) });
    };

    const setupNav = () => {
        const nav = document.querySelector('[data-ocr-nav]');
        if (!nav) {
            return;
        }
        paintPause();
        nav.querySelector('[data-ocr-toggle]')?.addEventListener('click', () => {
            const paused = !isPaused();
            try {
                if (paused) localStorage.setItem(PAUSE_KEY, '1');
                else localStorage.removeItem(PAUSE_KEY);
            } catch { /* this tab only */ }
            paintPause();
            document.dispatchEvent(new CustomEvent('dh:ocr-pause', { detail: paused }));
            // Point 61: the OCR log notes who paused or resumed (only the tab that was clicked).
            const logUrl = document.body?.dataset.ocrLog;
            if (logUrl) {
                const body = new FormData();
                body.append('event', paused ? 'paused' : 'resumed');
                fetch(logUrl, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
                    .catch(() => { /* only a log */ });
            }
        });
        // Another tab paused or resumed.
        window.addEventListener('storage', event => { if (event.key === PAUSE_KEY) paintPause(); });
        if (document.querySelector('[data-ocr-page]')) {
            return;
        }
        let timer = null;
        let last = 0;
        const refresh = () => {
            last = Date.now();
            getJson(nav.dataset.ocrNav).then(data => {
                if (!data) {
                    return;
                }
                showNav(data);
                if (!(data.open ?? data.waiting) && timer) {
                    clearInterval(timer);
                    timer = null;
                }
            });
        };
        const poll = () => {
            if (!timer) {
                timer = setInterval(() => { if (!document.hidden) refresh(); }, 10000);
            }
        };
        if (Number(nav.dataset.ocrOpen) > 0) {
            poll();
        }
        // The browser fallback finished a document: show it soon, at most every 3 s.
        document.addEventListener('dh:ocr-job', event => {
            if (event.detail === null && Date.now() - last > 3000) {
                refresh();
                poll();
            }
        });
    };

    const setupPage = () => {
        const page = document.querySelector('[data-ocr-page]');
        if (!page) {
            return;
        }
        const box = page.querySelector('[data-ocr-progress]');
        const bar = page.querySelector('[data-ocr-bar]');
        const text = page.querySelector('[data-ocr-progress-text]');
        const now = page.querySelector('[data-ocr-current]');
        const show = data => {
            const counts = data.counts ?? {};
            Object.entries(counts).forEach(([key, value]) => {
                const el = page.querySelector(`[data-ocr-count="${key}"]`);
                if (el) {
                    el.textContent = String(value);
                }
            });
            const done = counts.done ?? 0;
            const total = done + (counts.waiting ?? 0);
            const busy = (counts.waiting ?? 0) > 0 || (data.open ?? 0) > 0 || !!data.current;
            box.hidden = !busy;
            bar.max = Math.max(1, total);
            bar.value = done;
            text.textContent = (page.dataset.progressLabel ?? '').replace(':done', String(done)).replace(':total', String(total));
            const current = data.current;
            now.hidden = !current;
            if (current) {
                const withPercent = current.percent !== null && current.percent !== undefined;
                const label = withPercent ? page.dataset.currentPercentLabel : page.dataset.currentLabel;
                now.textContent = (label ?? '').replace(':percent', String(current.percent)).replace(':title', current.title);
            }
            showNav(data);
            return busy;
        };
        let busy = page.dataset.ocrBusy === '1';
        const tick = () => {
            if (!busy) {
                return;
            }
            if (document.hidden) {
                setTimeout(tick, 3000);
                return;
            }
            getJson(page.dataset.ocrPage).then(data => {
                if (data) {
                    busy = show(data);
                }
                setTimeout(tick, 3000);
            });
        };
        setTimeout(tick, 3000);
    };

    // A65/A68: "Erkennung auf dem Server einrichten" runs in the request; a progress dialog (not closable) meanwhile.
    const setupServerOcr = () => {
        const form = document.querySelector('form[data-ocr-setup]');
        const dialog = document.querySelector('[data-ocr-setup-progress]');
        if (!form || !dialog) {
            return;
        }
        form.addEventListener('submit', event => {
            event.preventDefault();
            dialog.hidden = false;
            const fail = () => {
                const bar = dialog.querySelector('[data-progress-bar]');
                if (bar) bar.hidden = true;
                const step = dialog.querySelector('[data-progress-step]');
                if (step) step.textContent = dialog.dataset.failed ?? '';
                const actions = dialog.querySelector('[data-progress-actions]');
                if (actions) actions.hidden = false;
            };
            dialog.querySelector('[data-progress-close]')?.addEventListener('click', () => location.reload(), { once: true });
            fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(response => (response.ok ? location.reload() : fail()))
                .catch(fail);
        });
    };

    // A69/A74: without cron (body[data-queue-tick]) the open, visible app works off the queue, one job per call:
    // every 10 s while jobs wait, every minute otherwise; stops once the server reports cron.
    const setupQueueTick = () => {
        const url = document.body?.dataset.queueTick;
        if (!url) {
            return;
        }
        const tick = () => {
            if (document.hidden) {
                setTimeout(tick, 10000);
                return;
            }
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
                .then(response => (response.ok ? response.json() : null))
                .catch(() => null)
                .then(data => {
                    if (data?.cron) {
                        return;
                    }
                    setTimeout(tick, data && data.waiting > 0 ? 10000 : 60000);
                });
        };
        setTimeout(tick, 10000);
    };

    // Browser fallback: send the percent of the current document at most every 2 s.
    const setupBrowserProgress = () => {
        let job = null;
        let last = 0;
        document.addEventListener('dh:ocr-job', event => {
            job = event.detail;
            last = 0;
        });
        document.addEventListener('dh:ocr-progress', event => {
            const m = event.detail;
            if (!job?.progress || m?.status !== 'recognizing text' || typeof m.progress !== 'number') {
                return;
            }
            const time = Date.now();
            if (time - last < 2000) {
                return;
            }
            last = time;
            const body = new FormData();
            body.append('percent', String(Math.round(m.progress * 100)));
            fetch(job.progress, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
                .catch(() => { /* only a progress hint */ });
        });
    };

    const start = () => {
        setupNav();
        setupPage();
        setupBrowserProgress();
        setupServerOcr();
        setupQueueTick();
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();

/* Package J */
// "Weitere Optionen" in the note dialogs (point 47, A38): collapsed by default, this browser remembers open or closed.
// Per window (the notes board can be open several times, point 73): new folds take the remembered state.
(() => {
    const KEY = 'notes.more';
    let open = false;
    try { open = localStorage.getItem(KEY) === '1'; } catch { /* not remembered */ }
    window.DocHamster.on('window:open', root => root.querySelectorAll?.('[data-note-more]').forEach(fold => { fold.open = open; }));
    // "toggle" does not bubble: caught on the way down.
    document.addEventListener('toggle', event => {
        const fold = event.target;
        if (!(fold instanceof HTMLDetailsElement) || !fold.matches('[data-note-more]') || fold.open === open) {
            return;
        }
        open = fold.open;
        try { localStorage.setItem(KEY, open ? '1' : '0'); } catch { /* not remembered */ }
        document.querySelectorAll('[data-note-more]').forEach(other => { if (other !== fold) other.open = open; });
    }, true);
})();

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

/* Package M (point 53): dialogs, dropdown panels, toasts and the tooltip sit in the browser's top layer
   (popover="manual") while shown, so a window's overflow, container queries or stacking can't cut or cover
   them, and a dialog's backdrop greys the whole app. The element stays where it is in the DOM: forms,
   closest('section[data-window]'), per-window names and window reloads keep working. Modules keep using
   the hidden attribute; this follows it. Shown later = drawn on top. */
(() => {
    if (!HTMLElement.prototype.showPopover) {
        return;
    }
    const LAYER = '[data-dialog], .dialog-backdrop, .dropdown-panel, .tip, .toast';
    const GAP = 8;
    // A dropdown panel hangs fixed below its button, right edges flush; it turns rightwards when there is no
    // room on the left and opens upwards when there is more room above than below. Long panels scroll.
    const place = panel => {
        const anchor = panel.parentElement?.querySelector(':scope > .icon-button') ?? panel.parentElement;
        if (!anchor || !panel.matches(':popover-open')) {
            return;
        }
        const a = anchor.getBoundingClientRect();
        panel.style.maxHeight = '';
        const w = panel.offsetWidth;
        const h = panel.offsetHeight;
        const vw = document.documentElement.clientWidth;
        const below = innerHeight - a.bottom - 2 * GAP;
        const above = a.top - 2 * GAP;
        const up = h > below && above > below;
        const room = Math.max(120, up ? above : below);
        let x = a.right - w;
        if (x < GAP) {
            x = a.left;
        }
        x = Math.max(GAP, Math.min(x, vw - w - GAP));
        const y = up ? Math.max(GAP, a.top - GAP - Math.min(h, room)) : a.bottom + GAP;
        Object.assign(panel.style, { left: `${Math.round(x)}px`, top: `${Math.round(y)}px`, right: 'auto', bottom: 'auto', maxHeight: `${Math.round(room)}px` });
    };
    // Removing the attribute also takes the element out of the top layer; it then shows in place again,
    // so code that focuses a field right after hidden = false still finds it visible.
    const drop = el => {
        if (el.hasAttribute('popover')) {
            el.removeAttribute('popover');
        }
    };
    const lift = el => {
        if (!el.isConnected || el.hidden) {
            return;
        }
        drop(el);
        el.setAttribute('popover', 'manual');
        try {
            el.showPopover();
        } catch {
            drop(el);
            return;
        }
        if (el.matches('.dropdown-panel')) {
            place(el);
        }
    };
    const start = () => {
        document.querySelectorAll(LAYER).forEach(lift);
        new MutationObserver(records => {
            const seen = new Set();
            records.forEach(record => {
                if (record.type === 'attributes') {
                    if (record.target.matches?.(LAYER)) {
                        seen.add(record.target);
                    }
                    return;
                }
                record.addedNodes.forEach(node => {
                    if (node.nodeType === 1) {
                        if (node.matches(LAYER)) {
                            seen.add(node);
                        }
                        node.querySelectorAll(LAYER).forEach(el => seen.add(el));
                    }
                });
            });
            // The tooltip is lifted again each time it shows, so it lies above a dialog opened after it.
            seen.forEach(el => {
                if (el.hidden) {
                    drop(el);
                } else if (!el.matches(':popover-open') || el.matches('.tip')) {
                    lift(el);
                }
            });
        }).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['hidden'] });
        const reposition = () => document.querySelectorAll('.dropdown-panel:popover-open').forEach(place);
        addEventListener('resize', reposition);
        document.addEventListener('scroll', event => {
            if (!event.target.closest?.('.dropdown-panel')) {
                reposition();
            }
        }, true);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();

/* Layout measurement (point 63 / A90) */
// ?debug=layout switches a measurement of jumps and flicker on for this tab (sessionStorage, so it survives window
// loads); ?debug=off ends it. It logs the last user action (pointerdown, click, drag start/end), every window size
// change (id, kind, old→new width, strip/open), layout shifts (PerformanceObserver 'layout-shift', with the moved
// elements and their rect deltas) and elements that were removed/hidden and came back within 300 ms. A small panel
// bottom right shows the count, copies a plain-text log ("Messung kopieren") and empties it ("Leeren"). Only a
// developer tool: its few German strings stay in this file.
(() => {
    const KEY = 'dh-debug-layout';
    try {
        const flag = new URLSearchParams(location.search).get('debug');
        if (flag === 'layout') {
            sessionStorage.setItem(KEY, '1');
        } else if (flag === 'off') {
            sessionStorage.removeItem(KEY);
        }
        if (sessionStorage.getItem(KEY) !== '1') {
            return;
        }
    } catch {
        return;
    }
    const t0 = performance.now();
    const log = [];
    let panel = null;
    let count = null;
    let last = '–';
    const stamp = () => `${((performance.now() - t0) / 1000).toFixed(3)}s`;
    const describe = el => {
        if (!(el instanceof Element)) {
            return el?.nodeName ?? '?';
        }
        const win = el.closest('section[data-window]');
        const own = `${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}${[...el.classList].slice(0, 3).map(name => `.${name}`).join('')}`;
        const where = win ? ` ${win === el ? '=' : 'in'} ${win.dataset.windowId ?? '?'}/${win.dataset.windowKind ?? '?'}` : '';
        return own + where;
    };
    const add = (type, text) => {
        log.push(`${stamp()} ${type.padEnd(7)} ${text}${type === 'action' ? '' : ` | nach: ${last}`}`);
        if (count) {
            count.textContent = `Layout-Messung: ${log.length}`;
        }
    };
    const mine = el => el instanceof Element && panel?.contains(el);

    // User actions
    document.addEventListener('pointerdown', event => {
        if (mine(event.target)) {
            return;
        }
        last = `pointerdown ${describe(event.target)} @${Math.round(event.clientX)},${Math.round(event.clientY)}`;
        add('action', last);
    }, true);
    document.addEventListener('click', event => {
        if (mine(event.target)) {
            return;
        }
        last = `click ${describe(event.target)}`;
        add('action', last);
    }, true);
    document.addEventListener('dh:win-drag', event => {
        const win = event.detail?.win;
        last = `drag ${event.detail?.phase} ${win?.dataset.windowId ?? '?'}/${win?.dataset.windowKind ?? '?'}`;
        add('action', last);
    }, true);

    // Layout shifts
    try {
        new PerformanceObserver(list => list.getEntries().forEach(entry => {
            const parts = (entry.sources ?? []).filter(source => !mine(source.node)).map(source => {
                const a = source.previousRect;
                const b = source.currentRect;
                return `${describe(source.node)} dx=${Math.round(b.x - a.x)} dy=${Math.round(b.y - a.y)} dw=${Math.round(b.width - a.width)} dh=${Math.round(b.height - a.height)}`;
            });
            add('shift', `${entry.value.toFixed(4)}${entry.hadRecentInput ? ' (nach Eingabe)' : ''} ${parts.join('; ') || '-'}`);
        })).observe({ type: 'layout-shift', buffered: true });
    } catch { /* browser without layout-shift entries */ }

    // Window sizes and strips
    const widths = new WeakMap();
    const strips = new WeakMap();
    const sizes = new ResizeObserver(entries => entries.forEach(entry => {
        const win = entry.target;
        const now = Math.round(entry.borderBoxSize?.[0]?.inlineSize ?? entry.contentRect.width);
        const before = widths.get(win);
        widths.set(win, now);
        if (before !== undefined && before !== now) {
            add('size', `${win.dataset.windowId ?? '?'}/${win.dataset.windowKind ?? '?'} ${before}→${now}px`);
        }
    }));
    const watch = win => {
        if (!widths.has(win)) {
            sizes.observe(win);
            strips.set(win, win.classList.contains('is-min'));
        }
    };

    // Removed or hidden and back within 300 ms
    const gone = new Map();
    const visible = el => (el.checkVisibility ? el.checkVisibility() : el.getClientRects().length > 0);
    const hidden = new WeakMap();
    const back = (el, how) => {
        const at = gone.get(el);
        if (at !== undefined && performance.now() - at <= 300) {
            add('flicker', `${describe(el)} ${how} und nach ${Math.round(performance.now() - at)} ms zurück`);
        }
        gone.delete(el);
    };
    const start = () => {
        document.querySelectorAll('section[data-window]').forEach(watch);
        new MutationObserver(records => records.forEach(record => {
            if (mine(record.target)) {
                return;
            }
            if (record.type === 'childList') {
                record.removedNodes.forEach(node => {
                    if (node instanceof Element) {
                        gone.set(node, performance.now());
                        if (node.matches('section[data-window]')) {
                            add('window', `${node.dataset.windowId ?? '?'}/${node.dataset.windowKind ?? '?'} entfernt`);
                        }
                    }
                });
                record.addedNodes.forEach(node => {
                    if (node instanceof Element) {
                        back(node, 'entfernt');
                        node.matches('section[data-window]') && watch(node);
                        node.querySelectorAll?.('section[data-window]').forEach(watch);
                    }
                });
                return;
            }
            const el = record.target;
            if (!(el instanceof Element) || !el.closest('[data-windows]')) {
                return;
            }
            if (el.matches('section[data-window]') && record.attributeName === 'class') {
                const was = strips.get(el);
                const now = el.classList.contains('is-min');
                if (was !== undefined && was !== now) {
                    add('window', `${el.dataset.windowId ?? '?'}/${el.dataset.windowKind ?? '?'} ${now ? 'zu Streifen' : 'geöffnet'}`);
                }
                strips.set(el, now);
            }
            const shown = visible(el);
            if (!shown && hidden.get(el) !== false) {
                gone.set(el, performance.now());
            } else if (shown && hidden.get(el) === false) {
                back(el, 'versteckt');
            }
            hidden.set(el, shown);
        })).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'hidden'] });

        // Panel (framework look: card surface, small buttons)
        panel = document.createElement('div');
        panel.className = 'layout-probe';
        count = document.createElement('strong');
        count.textContent = `Layout-Messung: ${log.length}`;
        const copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'button-quiet button-small';
        copy.textContent = 'Messung kopieren';
        copy.addEventListener('click', () => {
            const text = [`DocHamster Layout-Messung ${new Date().toISOString()} ${innerWidth}x${innerHeight} ${navigator.userAgent}`, ...log].join('\n');
            const done = label => {
                copy.textContent = label;
                setTimeout(() => { copy.textContent = 'Messung kopieren'; }, 1500);
            };
            // Without clipboard rights: the old copy command, else the console.
            const fallback = () => {
                const area = document.createElement('textarea');
                area.value = text;
                panel.append(area);
                area.select();
                const ok = document.execCommand('copy');
                area.remove();
                if (!ok) {
                    console.log(text);
                }
                done(ok ? 'Kopiert' : 'In Konsole');
            };
            (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject(new Error('no clipboard'))).then(() => done('Kopiert')).catch(fallback);
        });
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'button-quiet button-small';
        clear.textContent = 'Leeren';
        clear.addEventListener('click', () => {
            log.length = 0;
            count.textContent = 'Layout-Messung: 0';
        });
        panel.append(count, copy, clear);
        document.body.append(panel);
        // Exposed for the console and tests.
        window.DocHamster.layoutLog = log;
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
