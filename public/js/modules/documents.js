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
