/* Document properties (points 122.1-122.6, A244-A252); the popup "Markieren als …" is modules/mark-as.js */
(() => {
    const DocHamster = window.DocHamster;
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const api = () => document.querySelector('[data-properties-api]')?.dataset ?? null;
    const toast = (text, error = false) => {
        if (text && DocHamster.toast) {
            DocHamster.toast(text, { error });
        }
    };
    // JSON POST; a refused value (422) rejects with the server's reason ("Das ist kein Datum …").
    const post = (url, body) => fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' } })
        .then(async response => {
            const json = await response.json().catch(() => ({}));
            if (!response.ok) {
                const errors = json.errors ?? {};
                throw new Error(errors.value?.[0] ?? errors.property?.[0] ?? json.message ?? api()?.failed ?? '');
            }
            return json;
        });

    // "+ Eigenschaft" in the card: the same popup without a word.
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-mark-as]');
        if (button) {
            DocHamster.markAs({
                win: button.closest('section[data-window]'), documentId: button.dataset.markAs, anchor: button,
                text: button.dataset.markText ?? '', property: button.dataset.markProperty ?? null,
            });
        }
    });

    // --- The card "Eigenschaften" (A252) ----------------------------------------------------------------------------
    // It reloads itself in every window that shows the document; no ids inside, so nothing needs renaming.
    DocHamster.on('properties:changed', ({ documentId } = {}) => {
        document.querySelectorAll(`[data-doc-properties][data-document="${CSS.escape(String(documentId ?? ''))}"]`).forEach(card => {
            fetch(card.dataset.docProperties, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(response => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
                .then(json => {
                    const next = document.createRange().createContextualFragment(json.html).firstElementChild;
                    if (next && card.isConnected) {
                        card.replaceWith(next);
                    }
                })
                .catch(() => { /* the card stays as it was */ });
        });
    });
    // Point 214: the Belegdatum line under the title follows (same JSON, `date_html`).
    DocHamster.on('properties:changed', ({ documentId } = {}) => {
        document.querySelectorAll(`[data-doc-date][data-document="${CSS.escape(String(documentId ?? ''))}"]`).forEach(line => {
            fetch(line.dataset.docDate, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(response => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
                .then(json => {
                    const next = json.date_html ? document.createRange().createContextualFragment(json.date_html).firstElementChild : null;
                    if (next && line.isConnected) {
                        line.replaceWith(next);
                    }
                })
                .catch(() => { /* the line stays as it was */ });
        });
    });
    const cardOf = el => el?.closest?.('[data-doc-properties]');
    const changed = card => DocHamster.emit('properties:changed', { win: card.closest('section[data-window]'), documentId: card.dataset.document });
    // A value edited in place is confirmed; the card (and its copies in other windows) shows that.
    DocHamster.on('edit-in-place:saved', ({ box } = {}) => {
        const card = cardOf(box);
        if (card) changed(card);
    });
    // ✓ confirms a suggestion, X removes a value – in the background.
    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-prop-action]');
        const card = cardOf(form);
        if (!form || !card) {
            return;
        }
        event.preventDefault();
        post(form.action, new FormData(form)).then(json => {
            toast(json.message);
            changed(card);
        }).catch(error => toast(error.message || api()?.failed, true));
    });
    // Point 239: a value with a stored place has the icon button "Im Beleg zeigen" – a click goes to the page, zooms to
    // the place and outlines it briefly (document:highlight); hovering it (A419) only outlines it (document:outline).
    // Clicking into the value itself only edits it; the viewer stays where it is.
    const placeOf = button => {
        const row = button?.closest('.prop-row[data-page][data-box]');
        if (!row) return null;
        try {
            return { win: row.closest('section[data-window]'), page: Number(row.dataset.page), box: JSON.parse(row.dataset.box) };
        } catch {
            return null; // a broken box draws nothing
        }
    };
    document.addEventListener('click', event => {
        const place = placeOf(event.target.closest('[data-prop-show]'));
        if (place) DocHamster.emit('document:highlight', place);
    });
    document.addEventListener('pointerover', event => {
        const button = event.target.closest?.('[data-prop-show]');
        const place = button && !button.contains(event.relatedTarget) ? placeOf(button) : null;
        if (place) DocHamster.emit('document:outline', { ...place, on: true });
    });
    document.addEventListener('pointerout', event => {
        const button = event.target.closest?.('[data-prop-show]');
        const place = button && !button.contains(event.relatedTarget) ? placeOf(button) : null;
        if (place) DocHamster.emit('document:outline', { ...place, on: false });
    });

    // --- Einstellungen → Eigenschaften (point 122.2) -------------------------------------------------------------
    DocHamster.on('list:sorted', ({ list, ids }) => {
        const url = list.dataset.propertiesOrder;
        if (!url) {
            return;
        }
        const body = new FormData();
        ids.forEach(id => body.append('order[]', id));
        post(url, body).catch(() => toast(list.dataset.orderFailed, true));
    });
    // A saved name, switch or tag limit shows in its row at once.
    DocHamster.on('properties:saved', ({ json } = {}) => {
        const property = json?.property;
        const row = property && document.querySelector(`[data-property-row="${CSS.escape(property.id)}"]`);
        if (!row) {
            return;
        }
        row.querySelector('[data-property-name]').textContent = property.name;
        row.querySelector('[data-property-scope]').textContent = property.scope;
        row.classList.toggle('is-off', !property.active);
    });

    // --- Details view: property columns (A244) ------------------------------------------------------------------
    // The column chooser in the ⋯ menu saves the choice for the person and reloads only this window; a click on a
    // column head sorts on the server (?sort=…&dir=…) and reloads only this window's body.
    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-doc-columns]');
        const win = form?.closest('section[data-window]');
        if (!form || !win || !DocHamster.reloadWindow) {
            return;
        }
        event.preventDefault();
        const body = new FormData(form);
        if (event.submitter?.name) {
            body.set(event.submitter.name, event.submitter.value);
        }
        post(form.action, body).then(() => {
            const url = new URL(win.dataset.windowUrl ?? location.href, location.href);
            return DocHamster.reloadWindow(win, url, '.win-frame');
        }).catch(() => form.submit());
    });
    document.addEventListener('click', event => {
        const link = event.target.closest('a[data-doc-sort]');
        const win = link?.closest('section[data-window]');
        if (!link || !win || !DocHamster.reloadWindow || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }
        event.preventDefault();
        DocHamster.reloadWindow(win, new URL(link.href, location.href), '.win-body').catch(() => { location.href = link.href; });
    });
})();
