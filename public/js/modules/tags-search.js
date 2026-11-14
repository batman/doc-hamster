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
})();
