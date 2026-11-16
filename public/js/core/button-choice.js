// Umschalter as a choice ("Umschalter als Auswahl", points 135, 188; A283/A284) in place of radios or an "egal" select:
//   <span class="button-choice" data-choice><input type="hidden" name="match" value="">
//     <span class="switcher" role="group" aria-label="…"><button type="button" data-value="" aria-pressed="true">Alle</button>…</span></span>
// A click sets the hidden field and aria-pressed and sends `change` from the field, so forms and panels see it like a
// select. With data-choice-toggle a click on the pressed button sets it back to "" (nothing chosen). Delegated.
//
// Every Umschalter (.switcher, point 188.4): when its rail does not fit its room, our own dropdown takes its place
// (PO 2026-11-16: never the browser's select – it cannot show symbols): .is-tight + .dropdown.switcher-select, built here
// once – a button with the chosen entry's symbol and name, a panel of menu entries with their symbols. Picking an entry
// clicks that segment, so links, submit buttons and choices work as before. Measured whenever its parent changes size
// (like the bank toolbar's is-filters-tight). data-switch-static = never a dropdown (the module has its own narrow form).
(() => {
    const fieldOf = choice => choice.querySelector(':scope > input');
    const segmentsOf = rail => [...rail.children].filter(el => el.matches('button, a'));
    const selectOf = rail => (rail.nextElementSibling?.matches('.switcher-select') ? rail.nextElementSibling : null);
    const CHEVRON = '<svg class="icon switcher-select-chevron" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 9l6 6l6 -6"/></svg>';
    const nameOf = segment => (segment.dataset.label ?? segment.getAttribute('aria-label') ?? segment.textContent).replace(/\s+/g, ' ').trim();
    // An entry: the segment's own symbol (if it has one) and its name.
    const entry = (segment, element) => {
        const icon = segment.querySelector('.icon');
        element.replaceChildren();
        if (icon) element.append(icon.cloneNode(true));
        const label = document.createElement('span');
        label.textContent = nameOf(segment);
        element.append(label);
        return element;
    };
    const refresh = rail => {
        const select = selectOf(rail);
        if (!select) return;
        const segments = segmentsOf(rail);
        const index = segments.findIndex(segment => segment.getAttribute('aria-pressed') === 'true' || segment.getAttribute('aria-current') === 'page');
        const button = select.querySelector(':scope > .switcher-select-button');
        if (segments.length) entry(segments[Math.max(0, index)], button).insertAdjacentHTML('beforeend', CHEVRON);
        select.querySelectorAll('.dropdown-panel > .menu-item').forEach((item, i) => item.setAttribute('aria-checked', String(i === index)));
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
            select = document.createElement('div');
            select.className = 'dropdown switcher-select';
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'control-button switcher-select-button';
            button.setAttribute('aria-haspopup', 'true');
            button.setAttribute('aria-expanded', 'false');
            button.setAttribute('aria-label', rail.getAttribute('aria-label') ?? '');
            const panel = document.createElement('div');
            panel.className = 'dropdown-panel';
            panel.hidden = true;
            segmentsOf(rail).forEach((segment, i) => {
                const item = entry(segment, document.createElement('button'));
                item.type = 'button';
                item.className = 'menu-item';
                item.setAttribute('role', 'menuitemradio');
                item.dataset.segment = String(i);
                panel.append(item);
            });
            select.append(button, panel);
            rail.after(select);
        }
        refresh(rail);
        rail.classList.add('is-tight');
    };
    const railsOf = new WeakMap();
    const resize = typeof ResizeObserver === 'function'
        // Batch 16: fitted in the next frame, so a class change never resizes within the observer's round (loop warning).
        ? new ResizeObserver(entries => requestAnimationFrame(() => entries.forEach(entry => railsOf.get(entry.target)?.forEach(fit))))
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
    // The dropdown in place of a rail that does not fit: picking an entry clicks its segment (core/dropdowns.js closes it).
    document.addEventListener('click', event => {
        const item = event.target.closest?.('.switcher-select .menu-item[data-segment]');
        const rail = item?.closest('.switcher-select')?.previousElementSibling;
        if (rail?.matches('.switcher')) {
            segmentsOf(rail)[Number(item.dataset.segment)]?.click();
            setTimeout(() => refresh(rail));
        }
    });

    // Gruppe B (.chips, PO 2026-11-16): equal columns – every field as wide as the widest entry. Measured once the group
    // is visible (a closed search panel has no width yet), again when its fonts or entries change.
    const measure = rail => {
        if (!rail.offsetWidth) return;
        rail.classList.add('is-measuring');
        const widest = Math.max(...[...rail.children].map(chip => chip.getBoundingClientRect().width));
        rail.classList.remove('is-measuring');
        if (widest > 0) rail.style.setProperty('--chip-w', `${Math.ceil(widest)}px`);
    };
    const shown = typeof ResizeObserver === 'function'
        ? new ResizeObserver(entries => requestAnimationFrame(() => entries.forEach(entry => {
            if (!entry.target.style.getPropertyValue('--chip-w')) measure(entry.target);
        })))
        : null;
    const scanGroups = root => root?.querySelectorAll?.('.chips').forEach(rail => {
        shown?.observe(rail);
        measure(rail);
    });
    document.fonts?.ready.then(() => document.querySelectorAll('.chips').forEach(measure));

    document.addEventListener('DOMContentLoaded', () => scanGroups(document));
    document.addEventListener('DOMContentLoaded', () => scan(document));
    if (window.DocHamster) {
        window.DocHamster.syncChoices = sync;
        // Windows loaded later (after the module swapped its parts in the same event).
        window.DocHamster.on?.('window:open', root => requestAnimationFrame(() => {
            const box = root instanceof Element ? (root.closest('section[data-window]') ?? root) : document;
            scan(box);
            scanGroups(box);
        }));
    }
})();
