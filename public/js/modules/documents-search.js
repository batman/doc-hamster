/* The documents search panel (point 135, A282-A285; documents/index.blade.php, [data-doc-search]): the period buttons fill
   the date fields ("Eigener" shows them), "+ Filter hinzufügen" shows one property row, its X hides it again, and every
   section head keeps a summary of what is set. Scoped to its window; delegated, so windows loaded later work too. */
(() => {
    const DocHamster = window.DocHamster;
    if (!DocHamster) {
        return;
    }
    const pressed = el => el?.querySelector('[data-value][aria-pressed="true"]')?.textContent.trim() ?? '';
    const day = value => (value ? value.split('-').reverse().join('.') : '');
    const valueOf = (root, name) => root.querySelector(`[name="${CSS.escape(name)}"]`)?.value ?? '';
    const filled = row => [...row.querySelectorAll('input[name], select[name]')].some(input => input.value.trim() !== '');

    const summaries = {
        period: section => {
            const from = valueOf(section, 'from');
            const to = valueOf(section, 'to');
            if (!from && !to) return '';
            const choice = section.querySelector('[data-period-choice]');
            const preset = choice?.querySelector(':scope > input')?.value ?? '';
            const text = preset && preset !== 'custom' ? pressed(choice) : [day(from), day(to)].join(' – ').replace(/^ – | – $/g, '');
            const basis = pressed(section.querySelector('[data-choice]:has(> input[name="by"])'));
            return basis ? `${text} · ${basis}` : text;
        },
        tags: section => {
            const count = section.querySelectorAll('input[name="tags[]"]:checked').length;
            if (count === 0) return '';
            const chosen = (section.closest('[data-doc-search]')?.dataset.chosen ?? ':count').replace(':count', String(count));
            const any = section.querySelector('[data-choice]:has(> input[name="any"])');
            return valueOf(section, 'any') === '1' ? `${chosen} · ${pressed(any)}` : chosen;
        },
        status: section => [...section.querySelectorAll('[data-choice]')]
            .filter(choice => (choice.querySelector(':scope > input')?.value ?? '') !== '')
            .map(choice => (choice.dataset.summaryLabel ? `${choice.dataset.summaryLabel}: ${pressed(choice)}` : pressed(choice)))
            .join(' · '),
        props: section => [...section.querySelectorAll('[data-search-prop]:not([hidden])')]
            .filter(filled).map(row => row.dataset.name).join(', '),
    };
    const summarise = section => {
        const make = summaries[section?.dataset.searchSection];
        const slot = section?.querySelector(':scope > .card-head [data-card-summary]');
        if (!make || !slot) return;
        const text = make(section);
        slot.hidden = text === '';
        const span = slot.querySelector('span') ?? slot.appendChild(document.createElement('span'));
        span.textContent = text;
    };
    const summariseAll = panel => panel?.querySelectorAll('[data-search-section]').forEach(summarise);

    // The period: a preset fills both dates, "Eigener" shows the fields, none (pressed again) empties them.
    const periodChanged = choice => {
        const section = choice.closest('[data-search-section]');
        const value = choice.querySelector(':scope > input').value;
        const custom = section.querySelector('[data-period-custom]');
        const range = choice.querySelector(`[data-value="${CSS.escape(value)}"]`)?.dataset.range;
        if (value === 'custom') {
            custom.hidden = false;
            custom.querySelector('input')?.focus();
        } else {
            custom.hidden = true;
            const [from = '', to = ''] = (range ?? '').split(' ');
            section.querySelector('[name="from"]').value = from;
            section.querySelector('[name="to"]').value = to;
        }
    };
    // After the panel was reset or a chip removed a value: the period buttons and property rows follow the fields.
    const follow = panel => {
        const section = panel.querySelector('[data-search-section="period"]');
        const choice = section?.querySelector('[data-period-choice]');
        if (choice) {
            const dates = `${valueOf(section, 'from')} ${valueOf(section, 'to')}`;
            const preset = [...choice.querySelectorAll('[data-range]')].find(button => button.dataset.range === dates)?.dataset.value;
            choice.querySelector(':scope > input').value = preset ?? (dates.trim() ? 'custom' : '');
            DocHamster.syncChoices?.(section);
            section.querySelector('[data-period-custom]').hidden = (preset ?? (dates.trim() ? 'custom' : '')) !== 'custom';
        }
        panel.querySelectorAll('[data-search-prop]').forEach(row => {
            row.hidden = !filled(row);
        });
        syncAdd(panel);
        summariseAll(panel);
    };
    const syncAdd = panel => {
        const add = panel.querySelector('[data-prop-add]');
        add?.querySelectorAll('option[value]:not([value=""])').forEach(option => {
            option.disabled = !panel.querySelector(`[data-search-prop="${CSS.escape(option.value)}"]`)?.hidden;
        });
    };

    document.addEventListener('change', event => {
        const panel = event.target.closest?.('[data-doc-search]');
        if (!panel) return;
        const choice = event.target.closest('[data-period-choice]');
        if (choice) periodChanged(choice);
        const add = event.target.closest('[data-prop-add]');
        if (add) {
            const row = add.value && panel.querySelector(`[data-search-prop="${CSS.escape(add.value)}"]`);
            add.value = '';
            if (row) {
                row.hidden = false;
                syncAdd(panel);
                row.querySelector('input:not([type="hidden"]), select')?.focus();
            }
        }
        summarise(event.target.closest('[data-search-section]'));
    });
    document.addEventListener('input', event => {
        summarise(event.target.closest?.('[data-doc-search] [data-search-section]'));
    });
    document.addEventListener('click', event => {
        const remove = event.target.closest?.('[data-doc-search] [data-prop-remove]');
        if (!remove) return;
        const row = remove.closest('[data-search-prop]');
        const panel = remove.closest('[data-doc-search]');
        row.querySelectorAll('input[name]').forEach(input => { input.value = ''; });
        row.querySelectorAll('select[name]').forEach(select => { select.selectedIndex = 0; });
        DocHamster.syncChoices?.(row);
        row.hidden = true;
        syncAdd(panel);
        summarise(row.closest('[data-search-section]'));
        panel.querySelector('[data-prop-add]')?.focus();
    });
    DocHamster.on('search:reset', area => {
        const panel = area?.querySelector?.('[data-doc-search]');
        if (panel) follow(panel);
    });
})();
