// Aufklapp-Plus ("expanding plus", point 178, A354; docs/design/FRAMEWORK.md): a small plus button that turns into a
// name field with a ✓ while hovered, focused or tapped. Enter or ✓ sends its form; Escape empties it and folds it up;
// an empty field folds up when it loses the focus, one with text stays open. Delegated, so it works in every window.
//   <form class="expand-plus" data-expand-plus method="post" action="…">
//     <button type="button" class="icon-button expand-plus-open" aria-label="…" data-tooltip="…">plus</button>
//     <div class="field-addon expand-plus-field"><input class="input-small" name="name" aria-label="…">
//       <span class="addon-end"><button type="submit" class="control-button addon-button" aria-label="…" data-tooltip="…">check</button></span></div>
//   </form>
(() => {
    const fieldOf = box => box.querySelector('.expand-plus-field :is(input, textarea)');
    const filled = box => (fieldOf(box)?.value.trim() ?? '') !== '';
    const fold = box => box.classList.toggle('is-open', filled(box));
    // Escape gives the focus back to the plus; that must not open it again.
    let quiet = false;

    // The plus gets the focus (Tab, click, tap): open and move on into the field.
    document.addEventListener('focusin', event => {
        const plus = event.target.closest?.('[data-expand-plus] .expand-plus-open');
        if (!plus || quiet) return;
        const box = plus.closest('[data-expand-plus]');
        box.classList.add('is-open');
        fieldOf(box)?.focus();
    });
    document.addEventListener('focusout', event => {
        const box = event.target.closest?.('[data-expand-plus]');
        if (box && !box.contains(event.relatedTarget)) fold(box);
    });
    document.addEventListener('input', event => {
        const box = event.target.closest?.('[data-expand-plus]');
        if (box) box.classList.toggle('is-filled', filled(box));
    });
    document.addEventListener('keydown', event => {
        const box = event.key === 'Escape' && event.target.closest?.('[data-expand-plus]');
        if (!box) return;
        event.preventDefault();
        event.stopPropagation();
        const field = fieldOf(box);
        field.value = '';
        box.classList.remove('is-open', 'is-filled');
        quiet = true;
        box.querySelector('.expand-plus-open')?.focus();
        quiet = false;
        if (document.activeElement === field) field.blur();
    });
    // An empty field sends nothing; the focus stays in it.
    document.addEventListener('submit', event => {
        const box = event.target.closest?.('[data-expand-plus]');
        if (box && !filled(box)) {
            event.preventDefault();
            box.classList.add('is-open');
            fieldOf(box)?.focus();
        }
    });
})();
