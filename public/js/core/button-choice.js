// Umschalter as a choice ("Umschalter als Auswahl", points 135, 188; A283/A284) in place of radios or an "egal" select:
//   <span class="button-choice" data-choice><input type="hidden" name="match" value="">
//     <span class="switcher" role="group" aria-label="…"><button type="button" data-value="" aria-pressed="true">Alle</button>…</span></span>
// A click sets the hidden field and aria-pressed and sends `change` from the field, so forms and panels see it like a
// select. With data-choice-toggle a click on the pressed button sets it back to "" (nothing chosen). Delegated.
//
// Every Umschalter (.switcher, point 188.4): when its rail does not fit its room, a small select takes its place
// (.is-tight + .switcher-select, built here once); picking an entry clicks that segment, so links, submit buttons and
// choices work as before. Measured whenever its parent changes size (like the bank toolbar's is-filters-tight).
// data-switch-static = never a select (the module has its own narrow form).
(() => {
    const fieldOf = choice => choice.querySelector(':scope > input');
    const segmentsOf = rail => [...rail.children].filter(el => el.matches('button, a'));
    const selectOf = rail => (rail.nextElementSibling?.matches('.switcher-select') ? rail.nextElementSibling : null);
    const refresh = rail => {
        const select = selectOf(rail);
        if (select) select.selectedIndex = segmentsOf(rail).findIndex(segment => segment.getAttribute('aria-pressed') === 'true');
    };
    // Too narrow = the last segment ends beyond the rail's inner edge (segments never shrink; scrollWidth would also
    // count the tooltips).
    const overflows = rail => {
        const last = segmentsOf(rail).pop();
        const box = rail.getBoundingClientRect();
        if (!last || box.width === 0) return false;
        return last.getBoundingClientRect().right > box.right - parseFloat(getComputedStyle(rail).paddingRight) + 1;
    };
    const fit = rail => {
        rail.classList.remove('is-tight');
        if (!overflows(rail)) return;
        let select = selectOf(rail);
        if (!select) {
            select = document.createElement('select');
            select.className = 'input-small switcher-select';
            select.setAttribute('aria-label', rail.getAttribute('aria-label') ?? '');
            segmentsOf(rail).forEach(segment => select.add(new Option(
                (segment.dataset.label ?? segment.getAttribute('aria-label') ?? segment.textContent).replace(/\s+/g, ' ').trim())));
            rail.after(select);
        }
        refresh(rail);
        rail.classList.add('is-tight');
    };
    const railsOf = new WeakMap();
    const resize = typeof ResizeObserver === 'function'
        ? new ResizeObserver(entries => entries.forEach(entry => railsOf.get(entry.target)?.forEach(fit)))
        : null;
    const scan = root => root?.querySelectorAll?.('.switcher:not([data-switch-static])').forEach(rail => {
        const parent = rail.parentElement;
        if (!parent) return;
        if (resize && !railsOf.has(parent)) {
            railsOf.set(parent, new Set());
            resize.observe(parent);
        }
        railsOf.get(parent)?.add(rail);
        fit(rail);
    });

    // After code changed the fields (e.g. the search panel's reset): the pressed buttons follow the values.
    const sync = root => root?.querySelectorAll?.('[data-choice]').forEach(choice => {
        const value = fieldOf(choice)?.value ?? '';
        choice.querySelectorAll('[data-value]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.value === value)));
        choice.querySelectorAll('.switcher').forEach(refresh);
    });

    document.addEventListener('click', event => {
        const rail = event.target.closest?.('.switcher');
        if (rail) setTimeout(() => refresh(rail));
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
    // The select in place of a rail that does not fit: picking an entry clicks its segment.
    document.addEventListener('change', event => {
        const select = event.target.closest?.('.switcher-select');
        const rail = select?.previousElementSibling;
        if (rail?.matches('.switcher')) segmentsOf(rail)[select.selectedIndex]?.click();
    });

    document.addEventListener('DOMContentLoaded', () => scan(document));
    if (window.DocHamster) {
        window.DocHamster.syncChoices = sync;
        // Windows loaded later (after the module swapped its parts in the same event).
        window.DocHamster.on?.('window:open', root => requestAnimationFrame(() => scan(root instanceof Element ? (root.closest('section[data-window]') ?? root) : document)));
    }
})();
