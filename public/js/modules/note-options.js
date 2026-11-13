/* Package J */
// "Weitere Optionen" in the note dialogs (point 47, A38): collapsed by default, this browser remembers open or closed.
// Per window (the notes board can be open several times, point 73): new folds take the remembered state.
(() => {
    const KEY = 'notes.more';
    let open = false;
    try { open = localStorage.getItem(KEY) === '1'; } catch { /* not remembered */ }
    window.DocHamster.on('window:open', root => root.querySelectorAll?.('[data-note-more]').forEach(fold => { fold.open = open; }));
    // "toggle" does not bubble: caught on the way down.
    document.addEventListener('toggle', event => {
        const fold = event.target;
        if (!(fold instanceof HTMLDetailsElement) || !fold.matches('[data-note-more]') || fold.open === open) {
            return;
        }
        open = fold.open;
        try { localStorage.setItem(KEY, open ? '1' : '0'); } catch { /* not remembered */ }
        document.querySelectorAll('[data-note-more]').forEach(other => { if (other !== fold) other.open = open; });
    }, true);
})();
