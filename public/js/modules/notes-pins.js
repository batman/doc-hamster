/* Notes on Home (point 233, A395-A397; home/_pin.blade.php, dashboard/widgets/note*.blade.php).
   - The pin on a column's head or a note pins it to Home as its own widget or takes it off; it saves at once, every
     pin for the same thing (also in other notes windows) follows, and open Home windows draw their widgets again.
   - Opened from a pinned widget (?note=, data-focus-note), the board scrolls to that note and lights it up.
   - A pinned note's text on Home saves at once (a moment after typing, and on leaving the field) through the board's
     own address (notes.text). */
(() => {
    const DocHamster = window.DocHamster;
    if (!DocHamster) {
        return;
    }
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const post = (url, body) => fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' } })
        .then(async response => {
            const json = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(json.message || '');
            }
            return json;
        });

    // --- Pin buttons on the board -----------------------------------------------------------------------------------
    const show = (button, on) => {
        const label = on ? button.dataset.labelOn : button.dataset.labelOff;
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        button.setAttribute('aria-label', label);
        button.dataset.tooltip = label;
    };
    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-note-pin]');
        if (!button) {
            return;
        }
        const on = button.getAttribute('aria-pressed') !== 'true';
        const body = new FormData();
        body.append('type', button.dataset.pinType);
        body.append('id', button.dataset.pinId);
        body.append('pinned', on ? '1' : '0');
        post(button.dataset.notePin, body).then(json => {
            document.querySelectorAll(`[data-note-pin][data-pin-type="${button.dataset.pinType}"][data-pin-id="${button.dataset.pinId}"]`).forEach(each => show(each, on));
            DocHamster.toast?.(json.message);
            DocHamster.emit('dashboard:widgets');
        }).catch(error => DocHamster.toast?.(error.message || button.dataset.failed, { error: true }));
    });

    // --- Opened from Home: scroll to the note and light it up ---------------------------------------------------------
    DocHamster.on('window:open', root => root.querySelectorAll?.('[data-focus-note]').forEach(board => {
        const note = board.querySelector(`.note[data-note-id="${board.dataset.focusNote}"]`);
        board.removeAttribute('data-focus-note');
        if (!note) {
            return;
        }
        requestAnimationFrame(() => {
            note.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' });
            note.classList.add('is-found');
            setTimeout(() => note.classList.remove('is-found'), 2500);
        });
    }));

    // --- A pinned note's text on Home ----------------------------------------------------------------------------------
    const timers = new WeakMap();
    const save = area => {
        clearTimeout(timers.get(area));
        const text = area.value;
        if (text === (area.dataset.saved ?? area.defaultValue)) {
            return;
        }
        const body = new FormData();
        body.append('body', text);
        post(area.dataset.dashNoteText, body)
            .then(() => { area.dataset.saved = text; })
            .catch(error => DocHamster.toast?.(error.message || area.closest('[data-dash]')?.dataset.failed, { error: true }));
    };
    document.addEventListener('input', event => {
        const area = event.target.closest?.('[data-dash-note-text]');
        if (area) {
            clearTimeout(timers.get(area));
            timers.set(area, setTimeout(() => save(area), 800));
        }
    });
    document.addEventListener('focusout', event => {
        const area = event.target.closest?.('[data-dash-note-text]');
        if (area) {
            save(area);
        }
    });
})();
