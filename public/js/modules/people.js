/* Personen and Rollen (points 216, 218, 223): the user name follows the typed name in "Person hinzufügen" until it is
   typed by hand; after a save the open row's head follows (role name, "pausiert"). The saving itself is the framework's
   data-autosave form (modules/documents-forms.js). */
(() => {
    const DocHamster = window.DocHamster;
    const slug = text => text.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase()
        .replace(/ß/g, 'ss').replace(/[^a-z0-9._-]+/g, '.').replace(/^\.+|\.+$/g, '').slice(0, 40);

    document.addEventListener('input', event => {
        const name = event.target.closest?.('[data-username-from]');
        if (name) {
            const field = name.form?.querySelector(`[name="${name.dataset.usernameFrom}"]`);
            if (field && field.dataset.typed !== '1') field.value = slug(name.value);
            return;
        }
        if (event.target.matches?.('[data-username-field]')) {
            event.target.dataset.typed = event.target.value === '' ? '' : '1';
        }
    });

    DocHamster?.on?.('properties:saved', ({ form, json }) => {
        const row = form.closest('.accordion-row');
        const data = json?.role ?? json?.member;
        if (!row || !data) {
            return;
        }
        row.querySelectorAll('[data-paused-pill]').forEach(pill => { pill.hidden = !data.paused; });
        row.querySelector('.initials')?.classList.toggle('is-paused', Boolean(data.paused));
        const name = row.querySelector('[data-row-name]');
        if (name && json.role) name.textContent = data.name;
        const rolePill = row.querySelector('[data-role-pill]');
        if (rolePill && json.member) rolePill.textContent = data.role;
        const pills = row.querySelector('[data-restriction-pills]');
        if (pills && Array.isArray(data.pills)) {
            pills.replaceChildren(...data.pills.map(text => Object.assign(document.createElement('span'), { className: 'pill', textContent: text })));
        }
    });
})();
