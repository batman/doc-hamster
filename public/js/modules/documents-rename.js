/* Rename in place for folder and document rows, long press, a changed folder everywhere (A161-A194); split from documents.js (A315). */
(() => {
    const DocHamster = window.DocHamster;
    const docs = DocHamster?.docs;
    if (!docs) {
        return;
    }
    const { post, toast, fail, refresh } = docs;

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
    docs.pressTarget = pressTarget;
    docs.retitle = retitle;
})();
