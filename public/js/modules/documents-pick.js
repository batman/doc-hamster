/* Documents: the selection bar of a list window (point 196, A369, A370) – docked Auswahl-Leiste [data-pick-bar]. */
// documents-select.js keeps it current ("N ausgewählt", shown while a row is picked). Here: the head checkbox
// [data-pick-all] picks every row or none; the bar's Ordner-Menü moves all picked rows in one request (bulk move route;
// in the Posteingang as filing, "N Dokumente in „X“ abgelegt"); ✓ [data-pick-done] marks them done (Posteingang);
// X [data-pick-clear] clears the selection. Dragging a picked row carries the whole selection (documents-dnd.js).
// Point 226 (A392-A394): the checkboxes show only while "Auswählen" [data-pick-toggle] is on (remembered in this browser,
// for every folder) or while a row is picked with Ctrl/Shift (CSS :has). Switching off drops the selection.
(() => {
    const KEY = 'dh-pick-boxes';
    const root = document.documentElement;
    try {
        root.classList.toggle('show-picks', localStorage.getItem(KEY) === '1');
    } catch { /* no storage: boxes stay off */ }
    const pressed = () => document.querySelectorAll('[data-pick-toggle]').forEach(button => {
        button.setAttribute('aria-pressed', root.classList.contains('show-picks') ? 'true' : 'false');
    });
    document.addEventListener('DOMContentLoaded', pressed);
    document.addEventListener('click', event => {
        if (!event.target.closest?.('[data-pick-toggle]')) return;
        const on = !root.classList.contains('show-picks');
        root.classList.toggle('show-picks', on);
        try {
            localStorage.setItem(KEY, on ? '1' : '0');
        } catch { /* only for this page */ }
        if (!on) document.querySelectorAll('[data-doc].is-selected').forEach(row => window.DocHamster?.docs?.pick?.(row, false));
        pressed();
    });
})();

(() => {
    const DocHamster = window.DocHamster;
    const docs = DocHamster?.docs;
    if (!docs?.pick) {
        return;
    }
    const picked = scope => docs.docsIn(scope).filter(row => row.classList.contains('is-selected'));
    const leave = (rows, json, undo) => {
        rows.forEach(row => {
            docs.pick(row, false);
            row.hidden = true;
        });
        docs.toast(json.message, undo ? { undo } : {});
        docs.refresh();
        DocHamster.refreshBadges?.();
    };

    document.addEventListener('change', event => {
        const all = event.target.closest?.('[data-pick-all]');
        if (all) {
            docs.docsIn(docs.scopeOf(all)).forEach(row => docs.pick(row, all.checked));
        }
    });

    document.addEventListener('click', event => {
        const bar = event.target.closest?.('[data-pick-bar]');
        if (!bar) {
            return;
        }
        const scope = docs.scopeOf(bar);
        const rows = picked(scope);
        const cfg = docs.config();
        const folder = event.target.closest('.folder-menu [data-file-into]');
        const done = event.target.closest('[data-pick-done]');
        if (event.target.closest('[data-pick-clear]')) {
            rows.forEach(row => docs.pick(row, false));
        } else if (folder && cfg.move && rows.length > 0) {
            const inbox = scope.dataset?.windowKind === 'inbox';
            docs.post(cfg.move, { ids: rows.map(row => row.dataset.doc), folder: folder.dataset.fileInto, filed: inbox ? 1 : null })
                .then(json => leave(rows, json, () => docs.undoMoves?.(json.moves ?? [])))
                .catch(docs.fail);
        } else if (done && rows.length > 0) {
            done.disabled = true;
            docs.post(done.dataset.pickDone, { ids: rows.map(row => row.dataset.doc) })
                .then(json => leave(rows, json, null))
                .catch(docs.fail)
                .finally(() => { done.disabled = false; });
        }
    });
})();
