/* Documents: picking several document rows with Ctrl/Shift (A132) or their checkbox (point 196, shift = range);
   split from documents.js (A315). Every change updates the window's selection bar (documents-pick.js). */
(() => {
    const DocHamster = window.DocHamster;
    const docs = DocHamster?.docs;
    if (!docs) {
        return;
    }
    const { html } = docs;

    // --- Picking several document rows (A132) ------------------------------------------------------------------
    const scopeOf = el => el.closest('section[data-window]') ?? document;
    const docsIn = scope => [...scope.querySelectorAll('[data-doc]')].filter(row => !row.hidden);
    const anchors = new WeakMap();
    // The window's selection bar, the head checkbox and the row checkboxes follow the picked rows (once per task).
    const dirty = new Set();
    const sync = scope => {
        const rows = docsIn(scope);
        const count = rows.filter(row => row.classList.contains('is-selected')).length;
        const bar = scope.querySelector('[data-pick-bar]');
        if (bar) {
            bar.hidden = count === 0;
            const label = bar.querySelector('[data-pick-count]');
            if (label) label.textContent = (label.dataset.label ?? ':count').replace(':count', String(count));
        }
        scope.querySelectorAll('[data-pick-all]').forEach(all => {
            all.checked = count > 0 && count === rows.length;
            all.indeterminate = count > 0 && count < rows.length;
        });
    };
    const later = scope => {
        if (dirty.size === 0) {
            queueMicrotask(() => {
                dirty.forEach(sync);
                dirty.clear();
            });
        }
        dirty.add(scope);
    };
    const pick = (row, on = true) => {
        row.classList.toggle('is-selected', on);
        if (on) {
            row.setAttribute('aria-selected', 'true');
        } else {
            row.removeAttribute('aria-selected');
        }
        const box = row.querySelector('[data-pick]');
        if (box) box.checked = on;
        later(scopeOf(row));
    };
    const clear = scope => scope.querySelectorAll('[data-doc].is-selected').forEach(row => pick(row, false));
    let swallowClick = false;
    // The drag engine swallows the click that ends a drop (documents-dnd.js).
    docs.swallowClick = () => {
        swallowClick = true;
        setTimeout(() => { swallowClick = false; }, 0);
    };
    document.addEventListener('click', event => {
        if (swallowClick) {
            event.preventDefault();
            event.stopPropagation();
            swallowClick = false;
            return;
        }
        const row = event.target.closest?.('[data-doc]');
        const box = event.target.closest?.('[data-pick]');
        if (row && box) {
            // The row's checkbox (point 196): toggles this row; with Shift every row from the last one picked here.
            const scope = scopeOf(row);
            const rows = docsIn(scope);
            const from = rows.indexOf(anchors.get(scope));
            if (event.shiftKey && from >= 0) {
                const to = rows.indexOf(row);
                rows.slice(Math.min(from, to), Math.max(from, to) + 1).forEach(r => pick(r, box.checked));
            } else {
                pick(row, box.checked);
            }
            anchors.set(scope, row);
            return;
        }
        if (!row || event.target.closest('.row-actions, input, button, form, .dropdown')) {
            if (!event.target.closest?.('.row-actions, .toast, .dialog, .selection-bar, .pick-all, [data-pick-all], .tip')) {
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
    Object.assign(docs, { scopeOf, docsIn, pick });
})();
