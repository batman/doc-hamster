(() => {
    // Tags window: the search narrows the pills while typing, any letters inside the name. Delegated and scoped to its
    // own window, so a Tags window loaded later (or two of them) works too.
    document.addEventListener('input', event => {
        const search = event.target.closest?.('[data-tag-search]');
        if (!search) {
            return;
        }
        const wanted = search.value.trim().toLowerCase();
        (search.closest('section[data-window]') ?? document).querySelectorAll('[data-tag-name]').forEach(item => {
            item.hidden = !item.dataset.tagName.includes(wanted);
        });
    });
    // A tag renamed by its window title (PO 2026-11-16): its pills everywhere follow at once.
    window.DocHamster?.on?.('window:renamed', ({ json, name }) => {
        const id = json?.tag?.id;
        if (!id) return;
        document.querySelectorAll(`.tag-${CSS.escape(id)}`).forEach(pill => { pill.textContent = name; });
        document.querySelectorAll(`[data-tag-name] > .tag-${CSS.escape(id)}`).forEach(pill => { pill.parentElement.dataset.tagName = name.toLowerCase(); });
    });
})();
