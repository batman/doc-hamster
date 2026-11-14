/* Ordner-Menü (folder menu, A360, point 195): a compact explorer tree that picks a folder (FRAMEWORK.md). */
// - A dropdown opener [data-folder-menu] gets its panel filled from the window's <template data-folder-menu-tpl> each
//   time it opens, so only the path of the current folder is open (A368) – whatever was toggled last time is gone.
// - The chevron .menu-tree-toggle opens / closes its branch (right = closed, down = open); a click on the name picks.
//   Modules handle the pick ([data-file-into]); the panel follows its new height (core/top-layer.js places it).
(() => {
    const DocHamster = window.DocHamster;
    const winOf = el => el.closest('section[data-window]');

    document.addEventListener('click', event => {
        const opener = event.target.closest?.('[data-folder-menu]');
        const panel = opener?.parentElement.querySelector(':scope > .dropdown-panel');
        if (!panel || !panel.hidden) {
            return;
        }
        const tpl = winOf(opener)?.querySelector('template[data-folder-menu-tpl]');
        if (tpl) {
            panel.replaceChildren(tpl.content.cloneNode(true));
            panel.scrollTop = 0;
        }
    }, true);

    document.addEventListener('click', event => {
        const toggle = event.target.closest?.('.folder-menu .menu-tree-toggle[aria-expanded]');
        if (!toggle) {
            return;
        }
        const open = toggle.getAttribute('aria-expanded') !== 'true';
        // The labels sit on the menu itself (detail's "Verschieben …", A363) or on the window's template.
        const labels = toggle.closest('[data-collapse]') ?? winOf(toggle)?.querySelector('template[data-folder-menu-tpl]');
        const label = (open ? labels?.dataset.collapse : labels?.dataset.expand) ?? '';
        toggle.setAttribute('aria-expanded', String(open));
        toggle.dataset.tooltip = label;
        toggle.setAttribute('aria-label', `${label}: ${toggle.dataset.name ?? ''}`);
        const branch = toggle.closest('li')?.querySelector(':scope > .menu-tree');
        if (branch) {
            branch.hidden = !open;
        }
        const panel = toggle.closest('.dropdown-panel');
        if (panel) {
            DocHamster?.placeDropdown?.(panel);
        }
    });
})();
