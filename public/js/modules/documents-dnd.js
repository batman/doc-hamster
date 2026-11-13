/* Documents: dragging documents and folders onto folder rows, windows, the Papierkorb and rows with data-doc-drop
   (points 75-79, A133-A155, A269); split from documents.js (A315). */
(() => {
    const DocHamster = window.DocHamster;
    const docs = DocHamster?.docs;
    if (!docs) {
        return;
    }
    const { html, config, fill, docsLabel, still, toast, post, fail, refresh, confirmDialog, scopeOf, docsIn, pick } = docs;

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
        // Documents. A booking row of a Bank account window links them (A269); the Bank module answers and reloads.
        const booking = el.closest('[data-doc-drop]');
        if (booking) {
            return carry.trashed ? null : { type: 'link', el: booking, url: booking.dataset.docDrop };
        }
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
        if (target.type === 'link') {
            post(target.url, { ids }).then(json => {
                toast(json.message);
                DocHamster.emit('bank:changed', json);
            }).catch(fail);
        } else if (target.type === 'move') {
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
            docs.swallowClick();
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
        if (touch && !docs.pressTarget?.(event)) {
            timer = setTimeout(begin, 350); // a finger on the name waits for the long press instead (A190)
        }
    });
    Object.assign(docs, { winOf, folderOfWindow, setTarget });
})();
