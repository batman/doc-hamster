/* Popup "Markieren als …" (point 122.4; split from modules/properties.js) */
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
    const SWITCH_ICON = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 17l-18 0"/><path d="M6 10l-3 -3l3 -3"/><path d="M3 7l18 0"/><path d="M18 20l3 -3l-3 -3"/></svg>';
    const X_ICON = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>';
    let counter = 0;

    // --- DocHamster.markAs: the popup "Markieren als …" (point 122.4) -------------------------------------------------
    // markAs({ win, documentId, text, page, box, anchor }) lists the active properties this document may carry (A246),
    // shows live how the value would be stored (or why not), saves it as confirmed and emits properties:changed.
    // Resolves with the server's answer, or null when closed. A word click in the reading view calls it with text/page/box.
    // `property` (a key or id) picks that property at once (point 214: the Belegdatum line opens it with the Rechnungsdatum).
    // Point 242: `words` (indices of the marked OCR words on `page`) lets the server grow an IBAN or a spaced amount over
    // the following words of the line (A429); the popup then shows that suggestion – text, value and the stored place
    // (A430) follow it – before anything is saved.
    DocHamster.markAs = async ({ win = null, documentId, text = '', page = null, box = null, anchor = null, property: wanted = null, words = null } = {}) => {
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
        const subline = property => [types[property.type] ?? property.type, property.value].filter(Boolean).join(' · ');
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
        const marked = make('p', 'mark-as-text', text.trim());
        if (text.trim() !== '' && !wanted) {
            dialog.append(marked);
        }
        // The marked block as it came (A429 may grow it for one type; another type goes back to it).
        const given = { text: text.trim(), box, words };
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
            label.append(make('small', '', subline(property)));
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

        // 165.4: what the person typed (or marked) – the field may show the normalised value meanwhile.
        let raw = input.value;
        // 165.6: the order an ambiguous date is read in (null = the server's default: the sender's, else day first).
        let order = null;
        let orders = [];
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
        // The subline of the chosen date reads "Datum, lesen als <Tag, Monat, Jahr> = 3. April 2021" while the date reads
        // two ways; the orange link switches to the next reading (and round again).
        const showReading = () => {
            const property = chosen();
            list.querySelectorAll('.pick-row').forEach(row => {
                const own = properties.find(p => p.id === row.querySelector('input')?.value);
                const small = row.querySelector('small');
                if (!own || !small) return;
                if (own !== property || orders.length < 2) {
                    small.textContent = subline(own);
                    return;
                }
                const reading = orders.find(o => o.order === order) ?? orders[0];
                const link = make('button', 'button-link');
                link.type = 'button';
                link.dataset.tooltip = cfg.readOther;
                link.setAttribute('aria-label', `${cfg.readAs} ${reading.label} – ${cfg.readOther}`);
                link.innerHTML = SWITCH_ICON;
                link.append(reading.label);
                link.addEventListener('click', event => {
                    event.preventDefault();
                    const at = orders.findIndex(o => o.order === reading.order);
                    order = orders[(at + 1) % orders.length].order;
                    preview(0);
                });
                small.replaceChildren(`${types[own.type] ?? own.type}, ${cfg.readAs} `, link, ` = ${reading.text}`);
            });
        };
        let timer = 0;
        let run = 0;
        const preview = (wait = 200) => {
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
                if (order && property.type === 'date') body.append('order', order);
                const grow = given.words?.length && page && raw === given.text;
                if (grow) {
                    body.append('page', String(page));
                    given.words.forEach(i => body.append('words[]', String(i)));
                }
                post(fill(cfg.preview, documentId), body).then(json => {
                    if (mine !== run) return;
                    // A429: the server's suggestion takes the place of the marked words; back to them otherwise.
                    const block = grow ? json.block : null;
                    box = block?.box ?? given.box;
                    marked.textContent = block?.text ?? given.text;
                    if (block && input.value === raw) input.value = block.text;
                    orders = json.orders ?? [];
                    order = json.order ?? null;
                    showReading();
                    // 165.4: a valid value shows normalised (17.10.2025, 1.234,56 €) – not while typing, not for a
                    // date that reads two ways (its subline says how it is read).
                    if (!input.disabled && orders.length < 2 && document.activeElement !== input && json.value) {
                        input.value = json.value;
                    }
                    const replaces = json.current && json.current !== json.value ? ` · ${cfg.replaces.replace(':value', json.current)}` : '';
                    show(true, (block ? `${cfg.grown} · ` : '') + cfg.previewText.replace(':value', json.value) + replaces);
                }).catch(error => {
                    if (mine !== run) return;
                    box = given.box;
                    marked.textContent = given.text;
                    orders = [];
                    showReading();
                    show(false, error.message);
                });
            }, wait);
        };
        // A choice offers its values as a list; the clicked word preselects the matching one.
        const switchField = () => {
            const property = chosen();
            input.value = raw;
            order = null;
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
        input.addEventListener('input', () => {
            raw = input.value;
            order = null;
            preview();
        });

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
                if (order && property.type === 'date') body.append('order', order);
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
            const preset = wanted ? properties.find(p => p.key === wanted || p.id === wanted) : null;
            const radio = preset ? list.querySelector(`input[value="${CSS.escape(preset.id)}"]`) : null;
            if (radio) {
                radio.checked = true;
                switchField();
                input.focus();
                input.select();
            } else {
                (list.querySelector('input') ?? close).focus();
            }
        });
    };

})();
