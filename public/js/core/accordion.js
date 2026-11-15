/* Aufklappliste (accordion list, points 216, 218; FRAMEWORK.md): a click on a row's head button opens its body below
   (white marked look) and closes the other open row of the same list; a second click closes it. Delegated, so rows in
   windows loaded later work too. The server may draw a row open (`is-open`, body without `hidden`), e.g. after a create. */
(() => {
    const open = (row, state) => {
        const toggle = row.querySelector(':scope > .accordion-head > [data-accordion-toggle]');
        const body = row.querySelector(':scope > .accordion-body');
        if (!toggle || !body) {
            return;
        }
        row.classList.toggle('is-open', state);
        toggle.setAttribute('aria-expanded', String(state));
        body.hidden = !state;
        window.DocHamster?.emit?.('accordion:toggle', { row, open: state });
    };
    document.addEventListener('click', event => {
        const toggle = event.target.closest?.('[data-accordion-toggle]');
        const row = toggle?.closest('.accordion-row');
        if (!row) {
            return;
        }
        const state = toggle.getAttribute('aria-expanded') !== 'true';
        if (state) {
            row.parentElement?.querySelectorAll(':scope > .accordion-row.is-open').forEach(other => {
                if (other !== row) open(other, false);
            });
        }
        open(row, state);
    });
})();
