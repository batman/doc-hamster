(() => {
    const DocHamster = window.DocHamster;
    const root = document.documentElement;
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
    // reloadWindow (window-search.js) gives a reloaded body the same per-window ids.
    DocHamster.isolate = isolate;
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

    document.addEventListener('DOMContentLoaded', setupWindows);
})();
