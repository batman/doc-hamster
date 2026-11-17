(() => {
    const DocHamster = window.DocHamster;
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
                DocHamster.isolate(next, win.dataset.windowKey);
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

    // Filter and page links in a window (points 3, 4: Vorschläge, the pager): <a href data-window-reload="kind"> takes the
    // named query fields (space-separated) from its href onto the window's current address – so a search or another
    // filter stays – and reloads only that window (.win-frame: toolbar and body). A filter link starts on page 1.
    // Without JavaScript, or outside a window, it is a plain link.
    document.addEventListener('click', event => {
        const link = event.target.closest?.('a[data-window-reload]');
        const win = link?.closest('section[data-window]');
        if (!link || !win || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) {
            return;
        }
        event.preventDefault();
        const url = new URL(win.dataset.windowUrl ?? location.href, location.href);
        const from = new URL(link.href, location.href);
        const names = link.dataset.windowReload.split(' ').filter(Boolean);
        if (!names.includes('page')) {
            url.searchParams.delete('page');
        }
        names.forEach(name => {
            url.searchParams.delete(name);
            from.searchParams.getAll(name).forEach(value => url.searchParams.append(name, value));
        });
        DocHamster.reloadWindow(win, url, '.win-frame').then(() => win.querySelector('.win-body')?.scrollTo?.({ top: 0 }))
            .catch(() => { location.href = link.href; });
    });

    // Points 102-106: Enter, the magnifier or the panel's "Suchen" search (typing only with data-search-live); the
    // fields of the search panel (data-search-params names them) go along with q; X closes the search (drops them all).
    const setupWindowSearch = () => {
        const runs = new WeakMap();
        const fieldOf = form => form.querySelector('input[name="q"]');
        const ownedOf = form => ['q', ...(form.dataset.searchParams ?? '').split(' ').filter(Boolean)];
        const search = form => {
            const win = form.closest('section[data-window]');
            const field = fieldOf(form);
            const run = runs.get(form) ?? {};
            clearTimeout(run.timer);
            run.abort?.abort();
            const url = new URL(win?.dataset.windowUrl ?? location.href, location.href);
            const before = url.search;
            // The fields of the advanced search area (points 4-7) belong to this search; "name[]" fields carry several values.
            const area = win?.querySelector('[data-window-search-area]');
            const data = new FormData(form);
            // Ticks that all stand as they start (data-default, e.g. "Suchen in", point 187.1) stay out of the address.
            const atDefault = name => {
                const boxes = [...area.querySelectorAll(`input[type="checkbox"][name="${CSS.escape(name)}"]`)];
                return boxes.some(box => box.hasAttribute('data-default')) && boxes.every(box => box.checked === box.hasAttribute('data-default'));
            };
            area?.querySelectorAll('input[name], select[name]').forEach(input => {
                if (input.type === 'checkbox' && atDefault(input.name)) {
                    return;
                }
                if ((input.type !== 'checkbox' && input.type !== 'radio') || input.checked) {
                    data.append(input.name, input.value);
                }
            });
            ownedOf(form).forEach(name => {
                const values = data.getAll(name).map(value => String(value).trim()).filter(value => value !== '');
                url.searchParams.delete(name);
                (name.endsWith('[]') ? values : values.slice(-1)).forEach(value => url.searchParams.append(name, value));
            });
            const active = ownedOf(form).some(name => url.searchParams.has(name));
            form.querySelector('[data-window-search-clear]')?.toggleAttribute('hidden', !active && field.value === '');
            if (url.search === before) {
                return;
            }
            url.searchParams.delete('page'); // a new search starts on its first page
            if (!win) {
                location.href = url.href;
                return;
            }
            run.abort = new AbortController();
            runs.set(form, run);
            DocHamster.reloadWindow(win, url, '.win-body', run.abort.signal).then(next => {
                // A3: the number of active filters (the module puts it on its body as data-search-count) on the chevron.
                const count = Number(next.querySelector('[data-search-count]')?.dataset.searchCount ?? 0);
                const badge = form.querySelector('[data-window-search-count]');
                if (badge) {
                    badge.textContent = String(count);
                    badge.hidden = count === 0;
                    badge.parentElement.classList.toggle('is-active', count > 0);
                }
            }).catch(error => {
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
        const areaOf = form => form.closest('section[data-window]')?.querySelector('[data-window-search-area]');
        // Button groups as a choice follow their fields again; the module may update its panel (summaries, rows).
        const reset = area => {
            if (!area) return;
            DocHamster.syncChoices?.(area);
            DocHamster.emit('search:reset', area);
        };
        const clear = form => {
            fieldOf(form).value = '';
            areaOf(form)?.querySelectorAll('input, select').forEach(input => {
                if (input.tagName === 'SELECT') {
                    input.selectedIndex = 0;
                    return;
                }
                if (input.type === 'checkbox' || input.type === 'radio') {
                    input.checked = input.hasAttribute('data-default');
                } else {
                    input.value = '';
                }
            });
            reset(areaOf(form));
            search(form);
            fieldOf(form).focus();
        };
        document.addEventListener('input', event => {
            const form = event.target.closest('[data-window-search]');
            if (form && event.target === fieldOf(form)) {
                if (event.target.value !== '') {
                    form.querySelector('[data-window-search-clear]')?.removeAttribute('hidden');
                }
                if (form.hasAttribute('data-search-live')) {
                    later(form);
                }
            }
        });
        document.addEventListener('submit', event => {
            const form = event.target.closest('[data-window-search]');
            if (form) {
                event.preventDefault();
                search(form);
            }
        });
        const formOf = el => el?.closest('section[data-window]')?.querySelector('[data-window-search]');
        // The chevron slides the area open and shut; "Suchen" in it searches; Enter in one of its fields too.
        document.addEventListener('click', event => {
            const toggle = event.target.closest('[data-window-search-toggle]');
            if (toggle) {
                const area = areaOf(toggle.closest('[data-window-search]'));
                if (area) {
                    area.hidden = !area.hidden;
                    toggle.setAttribute('aria-expanded', String(!area.hidden));
                    area.querySelector('input:not([type="hidden"]), select')?.focus();
                }
                return;
            }
            const run = event.target.closest('[data-window-search-run]');
            if (run) {
                search(formOf(run));
                return;
            }
            // Quick picks for the period: data-period="from to" (empty = open end).
            const period = event.target.closest('[data-period]');
            if (period) {
                const area = period.closest('[data-window-search-area]');
                const [from = '', to = ''] = period.dataset.period.split(' ');
                area.querySelector('[name="from"]').value = from;
                area.querySelector('[name="to"]').value = to;
                search(formOf(period));
                return;
            }
            // An active-filter chip's X (point 6): that one value goes, the search runs again.
            const drop = event.target.closest('[data-window-search-drop]');
            if (drop) {
                event.preventDefault();
                const form = formOf(drop);
                const area = areaOf(form);
                const name = drop.dataset.windowSearchDrop;
                area?.querySelectorAll(`[name="${CSS.escape(name)}"]`).forEach(input => {
                    if (input.type === 'checkbox' || input.type === 'radio') {
                        if (drop.dataset.value === undefined || input.value === drop.dataset.value) {
                            input.checked = input.hasAttribute('data-default');
                        }
                    } else if (input.tagName === 'SELECT') {
                        input.selectedIndex = 0;
                    } else {
                        input.value = '';
                    }
                });
                if (name === 'q') {
                    fieldOf(form).value = '';
                }
                reset(area);
                search(form);
                return;
            }
        });
        document.addEventListener('keydown', event => {
            const area = event.target.closest?.('[data-window-search-area]');
            // 187.4: Enter in any field of the panel searches (text, date, ticks and chips).
            if (event.key === 'Enter' && area && event.target.matches('input')) {
                event.preventDefault();
                search(formOf(area));
            }
        });
        document.addEventListener('click', event => {
            // Also an X elsewhere in the window, e.g. in the head of a result list (point 106).
            const button = event.target.closest('[data-window-search-clear]');
            const form = button?.closest('[data-window-search]') ?? button?.closest('section[data-window]')?.querySelector('[data-window-search]');
            if (form) {
                event.preventDefault();
                clear(form);
            }
        });
        // Escape in a filled field clears the search; nothing else reacts to that key then.
        document.addEventListener('keydown', event => {
            const form = event.target.closest?.('[data-window-search]');
            if (event.key === 'Escape' && form && event.target === fieldOf(form) && fieldOf(form).value !== '') {
                event.preventDefault();
                event.stopImmediatePropagation();
                clear(form);
            }
        }, true);
    };

    document.addEventListener('DOMContentLoaded', setupWindowSearch);
})();
