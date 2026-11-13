(() => {
    const DocHamster = window.DocHamster;
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

    document.addEventListener('DOMContentLoaded', setupSortable);
})();
