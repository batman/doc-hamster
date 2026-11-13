/* Documents: picking several document rows with Ctrl/Shift (A132); split from documents.js (A315). */
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
    Object.assign(docs, { scopeOf, docsIn, pick });
})();
