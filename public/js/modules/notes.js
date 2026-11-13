(() => {
    const DocHamster = window.DocHamster;
    const root = document.documentElement;
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

    // Points 8, 9: the columns have no scrollbar of their own; the window scrolls. A column taller than the window scrolls
    // along until its end reaches the bottom, then stays (sticky top = window height - column height); shorter ones stay.
    const stickColumns = board => {
        const frame = board.closest('.win-frame');
        if (!frame || board.dataset.sticky) {
            return;
        }
        board.dataset.sticky = '1';
        const place = () => {
            const room = frame.clientHeight;
            board.querySelectorAll(':scope > .kanban-col').forEach(col => {
                col.style.setProperty('--stick', `${Math.min(0, room - col.offsetHeight - 16)}px`);
            });
        };
        const watch = new ResizeObserver(place);
        watch.observe(frame);
        new MutationObserver(() => {
            board.querySelectorAll(':scope > .kanban-col').forEach(col => watch.observe(col));
            place();
        }).observe(board, { childList: true });
        board.querySelectorAll(':scope > .kanban-col').forEach(col => watch.observe(col));
    };
    DocHamster.on('window:open', root => root.querySelectorAll?.('[data-notes]').forEach(stickColumns));
    document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-notes]').forEach(stickColumns));
})();
