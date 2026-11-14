/* Documents: row icons as tall as the two text lines (A144-A146) and the whole row opening the document (point 167); split from documents.js (A315). */
(() => {
    const DocHamster = window.DocHamster;
    const docs = DocHamster?.docs;
    if (!docs) {
        return;
    }

    // --- Row icons as tall as the two text lines (A144-A146) ---------------------------------------------------
    // The symbol's own box (getBBox, measured once per symbol in a hidden probe) becomes its viewBox, so every symbol
    // fills the 38 px slot; the stroke stays as thin as elsewhere (vector-effect: non-scaling-stroke in app.css).
    const SLOT = 38;
    const boxes = new Map();
    const measure = svg => {
        const key = svg.innerHTML;
        if (!boxes.has(key)) {
            const probe = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            probe.setAttribute('class', 'fit-probe');
            probe.setAttribute('viewBox', '0 0 24 24');
            probe.innerHTML = key;
            probe.querySelectorAll('[stroke="none"]').forEach(node => node.remove());
            document.body.append(probe);
            const box = probe.getBBox();
            probe.remove();
            boxes.set(key, box.width > 0 && box.height > 0 ? { x: box.x - 1, y: box.y - 1, w: box.width + 2, h: box.height + 2 } : null);
        }
        return boxes.get(key);
    };
    const fitIcons = root => root.querySelectorAll?.('.row-icon > svg.icon:not(.is-fit)').forEach(svg => {
        const box = measure(svg);
        if (!box) {
            return;
        }
        const scale = Math.min(SLOT / box.w, SLOT / box.h);
        svg.setAttribute('viewBox', `${box.x} ${box.y} ${box.w} ${box.h}`);
        svg.style.width = `${box.w * scale}px`;
        svg.style.height = `${box.h * scale}px`;
        svg.classList.add('is-fit');
    });
    // --- The whole row opens the document (point 167) ------------------------------------------------------------
    // [data-doc-row] (list, details, timeline, inbox): a click anywhere on the row – or Enter while the row has the focus –
    // clicks its title link (the existing data-window link, so windows.js opens it and marks the opener row). Row tools
    // (pencil, ✓, folder link, rename field) keep their own action; Ctrl/Shift picks rows instead (documents-select.js
    // stops those clicks in the capture phase), and the click that ends a drag or a long press is swallowed there too.
    const OWN = 'a, button, input, select, textarea, label, form, .row-actions, .dropdown, .doc-rename';
    const linkOf = row => row.querySelector('a[data-window]:has([data-doc-title])')
        ?? [...row.querySelectorAll('a[data-window]')].find(a => !a.matches('.doc-folder'));
    document.addEventListener('click', event => {
        const row = event.target.closest?.('[data-doc-row]');
        if (!row || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey
            || event.target.closest(OWN) || row.classList.contains('is-renaming')) {
            return;
        }
        linkOf(row)?.click();
    });
    document.addEventListener('keydown', event => {
        const row = event.target;
        if (event.key !== 'Enter' || event.defaultPrevented || !row.matches?.('[data-doc-row]') || row.classList.contains('is-renaming')) {
            return;
        }
        event.preventDefault();
        linkOf(row)?.click();
    });

    DocHamster.fitRowIcons = fitIcons;
    DocHamster.on('window:open', root => fitIcons(root ?? document));
    const fitAll = () => fitIcons(document);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fitAll);
    } else {
        fitAll();
    }
})();
