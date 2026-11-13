/* Colour palette (points 14-15) */
(() => {
    const DocHamster = window.DocHamster;
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // Einstellungen → Farben: the palette rows are a sortable list of the framework; a move saves the order.
    DocHamster?.on('list:sorted', ({ list, ids }) => {
        const url = list.dataset.paletteOrder;
        if (!url) {
            return;
        }
        const body = new FormData();
        ids.forEach(id => body.append('order[]', id));
        fetch(url, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' }, credentials: 'same-origin' })
            .then(response => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }
            })
            .catch(() => {
                if (!list.previousElementSibling?.matches('[data-order-error]')) {
                    const error = document.createElement('p');
                    error.className = 'error';
                    error.setAttribute('role', 'alert');
                    error.dataset.orderError = '';
                    error.textContent = list.dataset.orderFailed;
                    list.before(error);
                }
            });
    });

    // Note edit dialog: the picked palette colour (class c-rrggbb from tags.css) shows on the note right away;
    // closing without saving puts the old colour back.
    const colorClass = note => [...note.classList].find(name => /^c-[0-9a-f]{6}$/.test(name));
    const setupNotePreview = root => root.querySelectorAll?.('[data-note-preview]:not([data-palette-ready])').forEach(dialog => {
        dialog.dataset.paletteReady = '';
        const note = (dialog.closest('section[data-window]') ?? document).querySelector(`[data-note-id="${dialog.dataset.notePreview}"]`);
        const form = dialog.querySelector('form');
        if (!note || !form) {
            return;
        }
        let before = null;
        let saved = false;
        const show = name => {
            const now = colorClass(note);
            if (now) {
                note.classList.remove(now);
            }
            if (name) {
                note.classList.add(name);
            }
        };
        form.addEventListener('input', () => {
            const picked = form.querySelector('input[name="color"]:checked');
            if (picked && /^#[0-9a-f]{6}$/.test(picked.value)) {
                show(`c-${picked.value.slice(1)}`);
            }
        });
        form.addEventListener('submit', () => { saved = true; });
        new MutationObserver(() => {
            if (!dialog.hidden) {
                before = colorClass(note) ?? '';
                saved = false;
            } else if (before !== null && !saved) {
                show(before);
            }
        }).observe(dialog, { attributes: true, attributeFilter: ['hidden'] });
    });
    DocHamster.on('window:open', setupNotePreview);
})();
