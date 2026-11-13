// Button group as a choice ("Knopfgruppe als Auswahl", point 135, A283/A284) in place of radios or an "egal" select:
//   <span class="button-choice" data-choice><input type="hidden" name="match" value="">
//     <span class="button-group" role="group" aria-label="…"><button type="button" class="button-quiet button-small" data-value="" aria-pressed="true">Alle</button>…</span></span>
// A click sets the hidden field and aria-pressed and sends `change` from the field, so forms and panels see it like a
// select. With data-choice-toggle a click on the pressed button sets it back to "" (nothing chosen). Delegated.
(() => {
    const fieldOf = choice => choice.querySelector(':scope > input');
    // After code changed the fields (e.g. the search panel's reset): the pressed buttons follow the values.
    const sync = root => root?.querySelectorAll?.('[data-choice]').forEach(choice => {
        const value = fieldOf(choice)?.value ?? '';
        choice.querySelectorAll('[data-value]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.value === value)));
    });

    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-choice] [data-value]');
        const choice = button?.closest('[data-choice]');
        const field = choice && fieldOf(choice);
        if (!field) return;
        const again = button.getAttribute('aria-pressed') === 'true';
        const value = again && choice.hasAttribute('data-choice-toggle') ? '' : button.dataset.value;
        if (value === field.value) return;
        field.value = value;
        choice.querySelectorAll('[data-value]').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.value === value)));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    });

    if (window.DocHamster) window.DocHamster.syncChoices = sync;
})();
