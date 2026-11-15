// Aufklapp-Plus ("expanding plus", point 178, A354; docs/design/FRAMEWORK.md): a small plus button that turns into a
// name field with a ✓ while hovered, focused or tapped. Enter or ✓ sends its form; Escape empties it and folds it up;
// an empty field folds up when it loses the focus, one with text stays open. Delegated, so it works in every window.
//   <form class="expand-plus" data-expand-plus method="post" action="…">
//     <button type="button" class="icon-button expand-plus-open" aria-label="…" data-tooltip="…">plus</button>
//     <div class="field-addon expand-plus-field"><input class="input-small" name="name" aria-label="…">
//       <span class="addon-end"><button type="submit" class="control-button addon-button" aria-label="…" data-tooltip="…">check</button></span></div>
//   </form>
// Row variant (points 220, 221, A388) – "+ Eigenschaft hinzufügen" at the top of a list: `expand-plus is-row`, the plus is
// a `list-entry expand-plus-open` (symbol + text) and the open part a `pick-row is-static expand-plus-row` holding the
// name field (`.expand-plus-field`, its ✓ shows only while a name is typed) and any further fields of the new record
// (an Umschalter, a colour circle …). It opens on a click, tap or focus only (not on hover); Escape empties every
// field, puts the Umschalter back and folds it. `[data-show-when="field:value"]` inside the form shows only while the
// field named `field` has that value (e.g. "Auswahlwerte" only for Art = Auswahl). A field before the name field that
// should get the focus first carries `data-expand-focus` (Kartenkategorien: the code, then the tag with ✓).
(() => {
    const fieldOf = box => box.querySelector('.expand-plus-field :is(input, textarea)');
    const filled = box => (fieldOf(box)?.value.trim() ?? '') !== '';
    const typedOf = box => box.querySelectorAll('.expand-plus-row :is(input:not([type="hidden"], [type="color"]), textarea)');
    // A row with text in any of its fields stays open.
    const fold = box => box.classList.toggle('is-open', filled(box)
        || (box.classList.contains('is-row') && [...typedOf(box)].some(input => input.value.trim() !== '')));
    const sendOf = box => box.querySelector('.expand-plus-field [type="submit"]');
    const mark = box => {
        box.classList.toggle('is-filled', filled(box));
        const send = box.classList.contains('is-row') ? sendOf(box) : null;
        if (send) send.hidden = !filled(box);
    };
    // Fields shown only for one value of another field (data-show-when="type:choice").
    const follow = box => box.querySelectorAll('[data-show-when]').forEach(part => {
        const [name, value] = part.dataset.showWhen.split(':');
        const field = box.querySelector(`[name="${CSS.escape(name)}"]`);
        part.hidden = (field?.value ?? '') !== value;
    });
    // The start values of the hidden choice fields, to put back on Escape.
    const starts = new WeakMap();
    const remember = box => {
        if (!starts.has(box)) starts.set(box, [...box.querySelectorAll('[data-choice] > input[type="hidden"]')].map(input => [input, input.value]));
    };
    const open = box => {
        remember(box);
        box.classList.add('is-open');
        (box.querySelector('[data-expand-focus]') ?? fieldOf(box))?.focus();
    };
    // Escape gives the focus back to the plus; that must not open it again.
    let quiet = false;
    // A click inside the open row (an Umschalter segment, which Safari does not focus) must not fold it.
    let pressed = null;

    // The plus gets the focus (Tab, click, tap): open and move on into the field.
    document.addEventListener('focusin', event => {
        const plus = event.target.closest?.('[data-expand-plus] .expand-plus-open');
        if (!plus || quiet) return;
        open(plus.closest('[data-expand-plus]'));
    });
    document.addEventListener('pointerdown', event => {
        pressed = event.target.closest?.('[data-expand-plus]') ?? null;
        if (pressed) remember(pressed);
        setTimeout(() => { pressed = null; }, 0);
    }, true);
    document.addEventListener('click', event => {
        const plus = event.target.closest?.('[data-expand-plus] .expand-plus-open');
        if (plus) open(plus.closest('[data-expand-plus]'));
    });
    document.addEventListener('focusout', event => {
        const box = event.target.closest?.('[data-expand-plus]');
        if (box && box !== pressed && !box.contains(event.relatedTarget)) fold(box);
    });
    document.addEventListener('input', event => {
        const box = event.target.closest?.('[data-expand-plus]');
        if (box) mark(box);
    });
    document.addEventListener('change', event => {
        const box = event.target.closest?.('[data-expand-plus]');
        if (box) follow(box);
    });
    document.addEventListener('keydown', event => {
        const box = event.key === 'Escape' && event.target.closest?.('[data-expand-plus]');
        if (!box) return;
        event.preventDefault();
        event.stopPropagation();
        const field = fieldOf(box);
        field.value = '';
        if (box.classList.contains('is-row')) {
            typedOf(box).forEach(input => { input.value = ''; });
            (starts.get(box) ?? []).forEach(([input, value]) => { input.value = value; });
            window.DocHamster?.syncChoices?.(box);
            follow(box);
        }
        box.classList.remove('is-open', 'is-filled');
        mark(box);
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
