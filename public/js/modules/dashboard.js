/* Home dashboard (points 176, A349-A353; dashboard/index.blade.php). Everything is delegated and scoped to the
   dashboard of its own window, so several Home windows side by side work.
   - The pencil switches the edit mode (.is-editing): "+" adds a widget, each widget shows grip, size S/M/L and X.
     Every change saves at once (POST dashboard/layout with the widgets as they stand). Outside it nothing moves.
   - Reorder: drag the grip (pointer, mouse and finger) or arrow keys on it; Escape puts it back.
   - Foto: a picture dropped on it or chosen in edit mode is uploaded; Ablegen: files go to the menu's upload
     (documents.store without a folder = the Posteingang's default folder). */
(() => {
    const DocHamster = window.DocHamster;
    if (!DocHamster) {
        return;
    }
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const toast = (text, error = false) => { if (text) DocHamster.toast?.(text, { error }); };
    const send = (url, body) => fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        body: body instanceof FormData ? body : JSON.stringify(body),
        headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json', ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }) },
    }).then(async response => {
        const json = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(json.message || '');
        }
        return json;
    });
    const dashOf = el => el?.closest?.('section[data-window]')?.querySelector('[data-dash]') ?? el?.closest?.('[data-dash]');
    const gridOf = dash => dash.querySelector('[data-dash-grid]');
    const fail = (dash, error) => toast(error?.message || dash?.dataset.failed, true);
    const order = grid => [...grid.querySelectorAll(':scope > [data-widget]')];

    // --- Saving ------------------------------------------------------------------------------------------------
    const save = dash => send(dash.dataset.layout, { widgets: order(gridOf(dash)).map(li => ({ type: li.dataset.widget, size: li.dataset.size })) })
        .then(json => toast(json.message))
        .catch(error => fail(dash, error));

    // One widget drawn again from the server (after a pin, a link or a new photo), keeping the edit mode.
    const redraw = (dash, type) => {
        const win = dash.closest('section[data-window]');
        const url = win?.dataset.windowUrl ?? window.location.href;
        return fetch(url, { credentials: 'same-origin', headers: { 'X-DocHamster-Window': '1', Accept: 'text/html' } })
            .then(response => response.text())
            .then(text => {
                const fresh = new DOMParser().parseFromString(text, 'text/html').querySelector(`[data-dash-grid] > [data-widget="${type}"]`);
                const old = gridOf(dash).querySelector(`:scope > [data-widget="${type}"]`);
                if (fresh && old) {
                    old.replaceWith(document.adoptNode(fresh));
                } else if (fresh) {
                    gridOf(dash).prepend(document.adoptNode(fresh));
                }
            });
    };
    DocHamster.on('dashboard:pinned', () => document.querySelectorAll('[data-dash]').forEach(dash => redraw(dash, 'quick').catch(() => {})));

    // --- Edit mode ---------------------------------------------------------------------------------------------
    const setEditing = (dash, on) => {
        const win = dash.closest('section[data-window]');
        dash.classList.toggle('is-editing', on);
        const pencil = win?.querySelector('[data-dash-edit]');
        if (pencil) {
            pencil.setAttribute('aria-pressed', on ? 'true' : 'false');
            const label = on ? pencil.dataset.labelDone : pencil.dataset.labelEdit;
            pencil.setAttribute('aria-label', label);
            pencil.dataset.tooltip = label;
        }
        const add = win?.querySelector('[data-dash-add]');
        if (add) {
            add.hidden = !on;
        }
    };
    const addMenu = dash => dash.closest('section[data-window]')?.querySelector('[data-dash-add]');
    const syncAddMenu = dash => {
        const menu = addMenu(dash);
        if (!menu) {
            return;
        }
        const shown = new Set(order(gridOf(dash)).map(li => li.dataset.widget));
        let left = 0;
        menu.querySelectorAll('[data-dash-add-type]').forEach(item => {
            item.hidden = shown.has(item.dataset.dashAddType);
            left += item.hidden ? 0 : 1;
        });
        const none = menu.querySelector('[data-dash-add-none]');
        if (none) {
            none.hidden = left > 0;
        }
    };

    document.addEventListener('click', event => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const pencil = target.closest('[data-dash-edit]');
        if (pencil) {
            const dash = dashOf(pencil);
            if (dash) {
                setEditing(dash, !dash.classList.contains('is-editing'));
            }
            return;
        }
        const add = target.closest('[data-dash-add-type]');
        if (add) {
            const dash = dashOf(add);
            const body = new FormData();
            body.append('type', add.dataset.dashAddType);
            send(dash.dataset.add, body).then(json => {
                const holder = document.createElement('template');
                holder.innerHTML = json.html ?? '';
                const li = holder.content.firstElementChild;
                if (li) {
                    gridOf(dash).append(li);
                    li.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
                syncAddMenu(dash);
                toast(json.message);
            }).catch(error => fail(dash, error));
            return;
        }
        const size = target.closest('[data-dash-size]');
        if (size) {
            const li = size.closest('[data-widget]');
            const dash = dashOf(li);
            li.classList.remove(`is-${li.dataset.size}`);
            li.dataset.size = size.dataset.dashSize;
            li.classList.add(`is-${li.dataset.size}`);
            li.querySelectorAll('[data-dash-size]').forEach(b => b.setAttribute('aria-pressed', b === size ? 'true' : 'false'));
            save(dash);
            return;
        }
        const remove = target.closest('[data-dash-remove]');
        if (remove) {
            const li = remove.closest('[data-widget]');
            const dash = dashOf(li);
            li.remove();
            syncAddMenu(dash);
            save(dash);
            return;
        }
        const unpin = target.closest('[data-dash-unpin]');
        if (unpin) {
            const dash = dashOf(unpin);
            const body = new FormData();
            body.append('url', unpin.dataset.dashUnpin);
            send(dash.dataset.unpin, body).then(json => {
                toast(json.message);
                return redraw(dash, 'quick');
            }).catch(error => fail(dash, error));
            return;
        }
        const photoRemove = target.closest('[data-dash-photo-remove]');
        if (photoRemove) {
            const dash = dashOf(photoRemove);
            send(dash.dataset.photoDelete, new FormData()).then(json => {
                toast(json.message);
                return redraw(dash, 'photo');
            }).catch(error => fail(dash, error));
        }
    });

    // "+ Link" in Schnellzugriff: posted in the background, the tiles are drawn again.
    document.addEventListener('submit', event => {
        const form = event.target.closest?.('[data-dash-link]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const dash = dashOf(form);
        send(form.action, new FormData(form)).then(json => {
            toast(json.message);
            return redraw(dash, 'quick');
        }).catch(error => fail(dash, error));
    });

    // --- Files: the photo and the drop field --------------------------------------------------------------------
    const uploadPhoto = (dash, file) => {
        const body = new FormData();
        body.append('photo', file);
        return send(dash.dataset.photo, body).then(json => {
            toast(json.message);
            return redraw(dash, 'photo');
        }).catch(error => fail(dash, error));
    };
    const uploadFiles = (dash, files) => {
        if (!dash.dataset.upload || files.length === 0) {
            return;
        }
        const field = dash.querySelector('[data-dash-drop]');
        field?.classList.add('is-busy');
        // The server takes 20 files per request (like the menu's drop).
        const chunks = [];
        for (let i = 0; i < files.length; i += 20) {
            chunks.push(files.slice(i, i + 20));
        }
        let last = null;
        chunks.reduce((chain, chunk) => chain.then(() => {
            const body = new FormData();
            chunk.forEach(file => body.append('files[]', file));
            return send(dash.dataset.upload, body).then(json => { last = json; });
        }), Promise.resolve())
            .then(() => {
                toast(last?.message);
                DocHamster.docs?.refresh?.();
                return redraw(dash, 'inbox').catch(() => {});
            })
            .catch(error => fail(dash, error))
            .finally(() => field?.classList.remove('is-busy'));
    };
    document.addEventListener('change', event => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'file') {
            return;
        }
        const dash = dashOf(input);
        if (input.matches('[data-dash-photo-input]') && input.files?.length) {
            uploadPhoto(dash, input.files[0]);
        } else if (input.matches('[data-dash-drop-input]') && input.files?.length) {
            uploadFiles(dash, [...input.files]);
        }
        input.value = '';
    });

    // The menu's file drag (modules/documents-upload.js) would refuse these places, so they answer the drag
    // themselves; the drop still reaches the document, which only ends the menu's file drag.
    const carriesFiles = event => [...(event.dataTransfer?.types ?? [])].includes('Files');
    const dropField = el => el instanceof Element ? el.closest('[data-dash-drop], [data-dash-photo-drop]') : null;
    document.addEventListener('dragover', event => {
        const field = dropField(event.target);
        if (!field || !carriesFiles(event)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        event.dataTransfer.dropEffect = 'copy';
        field.classList.add('is-drop-target');
    }, true);
    document.addEventListener('dragleave', event => {
        const field = dropField(event.target);
        if (field && !field.contains(event.relatedTarget)) {
            field.classList.remove('is-drop-target');
        }
    });
    document.addEventListener('drop', event => {
        const field = dropField(event.target);
        if (!field || !carriesFiles(event)) {
            return;
        }
        event.preventDefault();
        field.classList.remove('is-drop-target');
        const dash = dashOf(field);
        const files = [...event.dataTransfer.files];
        if (field.matches('[data-dash-photo-drop]')) {
            const picture = files.find(file => file.type.startsWith('image/'));
            if (picture) {
                uploadPhoto(dash, picture);
            }
        } else {
            uploadFiles(dash, files);
        }
    }, true);

    // --- Reorder: drag the tool line (point 192), or arrow keys on it -------------------------------------------------------------
    document.addEventListener('pointerdown', event => {
        const grip = event.target instanceof Element ? event.target.closest('[data-dash-grip]') : null;
        if (!grip || event.button !== 0 || event.target.closest('button, a, input')) {
            return;
        }
        const item = grip.closest('[data-widget]');
        const grid = item.parentElement;
        const dash = dashOf(item);
        if (!dash?.classList.contains('is-editing')) {
            return;
        }
        event.preventDefault();
        const before = order(grid);
        item.classList.add('is-dragging');
        document.documentElement.classList.add('is-sorting');
        const move = e => {
            const over = document.elementFromPoint(e.clientX, e.clientY)?.closest('[data-widget]');
            if (!over || over === item || over.parentElement !== grid) {
                return;
            }
            // Towards the widget under the pointer: past it when coming from before, else in front of it.
            const list = order(grid);
            grid.insertBefore(item, list.indexOf(item) < list.indexOf(over) ? over.nextElementSibling : over);
        };
        const end = keep => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('pointercancel', cancel);
            window.removeEventListener('keydown', key, true);
            item.classList.remove('is-dragging');
            document.documentElement.classList.remove('is-sorting');
            if (!keep) {
                before.forEach(li => grid.append(li));
            } else if (order(grid).some((li, i) => li !== before[i])) {
                save(dash);
            }
        };
        const up = () => end(true);
        const cancel = () => end(false);
        const key = e => {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                end(false);
            }
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        window.addEventListener('pointercancel', cancel);
        window.addEventListener('keydown', key, true);
    });
    document.addEventListener('keydown', event => {
        const grip = event.target instanceof Element && event.target.matches('[data-dash-grip]') ? event.target : null;
        if (!grip || !['ArrowLeft', 'ArrowUp', 'ArrowRight', 'ArrowDown'].includes(event.key)) {
            return;
        }
        event.preventDefault();
        const item = grip.closest('[data-widget]');
        const back = event.key === 'ArrowLeft' || event.key === 'ArrowUp';
        const next = back ? item.previousElementSibling : item.nextElementSibling?.nextElementSibling ?? null;
        if (back && !next) {
            return;
        }
        if (!back && !item.nextElementSibling) {
            return;
        }
        item.parentElement.insertBefore(item, next);
        grip.focus();
        save(dashOf(item));
    });
})();
