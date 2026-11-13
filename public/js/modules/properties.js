/* Document properties (points 122.1-122.6, A244-A252) */
(() => {
    const DocHamster = window.DocHamster;
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const api = () => document.querySelector('[data-properties-api]')?.dataset ?? null;
    const fill = (template, id) => template.replace('__ID__', encodeURIComponent(id));
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
    const make = (tag, className = '', text = null) => {
        const el = document.createElement(tag);
        if (className) {
            el.className = className;
        }
        if (text !== null) {
            el.textContent = text;
        }
        return el;
    };
    const X_ICON = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>';
    let counter = 0;

    // --- DocHamster.markAs: the popup "Markieren als …" (point 122.4) -------------------------------------------------
    // markAs({ win, documentId, text, page, box, anchor }) lists the active properties this document may carry (A246),
    // shows live how the value would be stored (or why not), saves it as confirmed and emits properties:changed.
    // Resolves with the server's answer, or null when closed. A word click in the reading view calls it with text/page/box.
    DocHamster.markAs = async ({ win = null, documentId, text = '', page = null, box = null, anchor = null } = {}) => {
        const cfg = api();
        if (!cfg || !documentId) {
            return null;
        }
        const host = win ?? anchor?.closest?.('section[data-window]') ?? null;
        let offered;
        try {
            offered = await fetch(fill(cfg.card, documentId), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(response => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))));
        } catch {
            toast(cfg.failed, true);
            return null;
        }
        const properties = offered.properties ?? [];
        const types = JSON.parse(cfg.types || '{}');
        const name = `mark-as-${Date.now().toString(36)}-${++counter}`;

        const backdrop = make('div', 'dialog-backdrop');
        backdrop.dataset.dialog = name;
        backdrop.hidden = true;
        const dialog = make('div', 'dialog mark-as');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', `${name}-title`);
        const close = make('button', 'icon-button button-small dialog-x');
        close.type = 'button';
        close.dataset.dialogClose = '';
        close.setAttribute('aria-label', cfg.cancel);
        close.innerHTML = X_ICON;
        const title = make('h2', '', cfg.title);
        title.id = `${name}-title`;
        dialog.append(close, title);
        if (text.trim() !== '') {
            dialog.append(make('p', 'mark-as-text', text.trim()));
        }
        backdrop.append(dialog);

        if (properties.length === 0) {
            dialog.append(make('p', 'list-empty', cfg.none));
        }
        const form = make('form', 'mark-as-form');
        form.noValidate = true;
        const list = make('div', 'pick-list mark-as-list');
        list.setAttribute('role', 'radiogroup');
        list.setAttribute('aria-label', cfg.property);
        properties.forEach(property => {
            const row = make('label', 'pick-row');
            const radio = make('input');
            radio.type = 'radio';
            radio.name = 'property';
            radio.value = property.id;
            const label = make('span');
            label.append(make('strong', '', property.name));
            label.append(make('small', '', [types[property.type] ?? property.type, property.value].filter(Boolean).join(' · ')));
            row.append(radio, label);
            list.append(row);
        });
        const field = make('div', 'field');
        const fieldLabel = make('label', '', cfg.value);
        fieldLabel.htmlFor = `${name}-value`;
        const addon = make('div', 'field-addon');
        const input = make('input');
        input.id = `${name}-value`;
        input.name = 'value';
        input.value = text.trim();
        input.autocomplete = 'off';
        const select = make('select');
        select.id = `${name}-choice`;
        select.hidden = true;
        select.disabled = true;
        select.setAttribute('aria-labelledby', `${name}-title`);
        const end = make('span', 'addon-end');
        const state = make('span', 'addon-state');
        state.setAttribute('role', 'img');
        state.hidden = true;
        end.append(state);
        addon.append(input, select, end);
        const hint = make('p', 'field-hint');
        hint.setAttribute('aria-live', 'polite');
        field.append(fieldLabel, addon, hint);
        const actions = make('div', 'dialog-actions');
        const save = make('button', '', cfg.save);
        save.type = 'submit';
        actions.append(save);
        if (properties.length > 0) {
            form.append(list, field, actions);
            dialog.append(form);
        }

        const chosen = () => properties.find(property => property.id === form.querySelector('input[name="property"]:checked')?.value) ?? null;
        const valueOf = () => (select.disabled ? input.value : select.value);
        const show = (ok, message) => {
            addon.classList.toggle('is-ok', ok === true);
            addon.classList.toggle('is-error', ok === false);
            field.classList.toggle('has-error', ok === false);
            state.hidden = ok === null;
            state.setAttribute('aria-label', message);
            hint.textContent = message;
        };
        let timer = 0;
        let run = 0;
        const preview = () => {
            clearTimeout(timer);
            const property = chosen();
            if (!property) {
                show(null, '');
                return;
            }
            timer = setTimeout(() => {
                const mine = ++run;
                const body = new FormData();
                body.append('property', property.id);
                body.append('value', valueOf());
                post(fill(cfg.preview, documentId), body).then(json => {
                    if (mine !== run) return;
                    const replaces = json.current && json.current !== json.value ? ` · ${cfg.replaces.replace(':value', json.current)}` : '';
                    show(true, cfg.previewText.replace(':value', json.value) + replaces);
                }).catch(error => {
                    if (mine === run) show(false, error.message);
                });
            }, 200);
        };
        // A choice offers its values as a list; the clicked word preselects the matching one.
        const switchField = () => {
            const property = chosen();
            const isChoice = property?.type === 'choice';
            select.hidden = !isChoice;
            select.disabled = !isChoice;
            input.hidden = isChoice;
            input.disabled = isChoice;
            fieldLabel.htmlFor = isChoice ? select.id : input.id;
            if (isChoice) {
                select.replaceChildren(...property.options.map(option => {
                    const el = make('option', '', option);
                    el.value = option;
                    el.selected = option.toLowerCase() === input.value.trim().toLowerCase();
                    return el;
                }));
            }
            preview();
        };
        form.addEventListener('change', event => {
            if (event.target.name === 'property') switchField();
            else preview();
        });
        input.addEventListener('input', preview);

        return new Promise(resolve => {
            let result = null;
            form.addEventListener('submit', event => {
                event.preventDefault();
                const property = chosen();
                if (!property) {
                    show(false, cfg.pick);
                    list.querySelector('input')?.focus();
                    return;
                }
                const body = new FormData();
                body.append('property', property.id);
                body.append('value', valueOf());
                if (page !== null && page !== undefined) {
                    body.append('page', String(page));
                }
                if (box) {
                    ['x', 'y', 'w', 'h'].forEach(part => body.append(`box[${part}]`, String(box[part] ?? '')));
                }
                save.disabled = true;
                post(fill(cfg.set, documentId), body).then(json => {
                    result = json;
                    toast(json.message);
                    backdrop.hidden = true;
                    DocHamster.emit('properties:changed', { win: host, documentId });
                }).catch(error => show(false, error.message)).finally(() => { save.disabled = false; });
            });
            // Escape, the backdrop and the X close it (core/dialogs.js); then it goes.
            new MutationObserver(() => {
                if (backdrop.hidden) {
                    backdrop.remove();
                    anchor?.focus?.();
                    resolve(result);
                }
            }).observe(backdrop, { attributes: true, attributeFilter: ['hidden'] });
            (host ?? document.body).append(backdrop);
            backdrop.hidden = false;
            (list.querySelector('input') ?? close).focus();
        });
    };

    // "+ Eigenschaft" in the card: the same popup without a word.
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-mark-as]');
        if (button) {
            DocHamster.markAs({ win: button.closest('section[data-window]'), documentId: button.dataset.markAs, anchor: button });
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
    // A value with a stored place: the reading view draws it (it listens to document:highlight).
    document.addEventListener('click', event => {
        const row = event.target.closest('.prop-row[data-page][data-box]');
        if (!row || event.target.closest('form, button')) {
            return;
        }
        try {
            DocHamster.emit('document:highlight', { win: row.closest('section[data-window]'), page: Number(row.dataset.page), box: JSON.parse(row.dataset.box) });
        } catch { /* a broken box draws nothing */ }
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
