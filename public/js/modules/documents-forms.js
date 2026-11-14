/* Properties modules that save on change and the field with symbol (A191-A199); split from documents.js (A315). */
(() => {
    const DocHamster = window.DocHamster;
    const docs = DocHamster?.docs;
    if (!docs) {
        return;
    }
    const { post, toast, fail } = docs;

    // --- Properties modules save on change (A191-A194, FRAMEWORK.md "Properties module") --------------------------
    // form[data-autosave]: every choice (select, radio, checkbox, colour) saves at once. A text field with a ✓ inside
    // (.field-addon with [data-field-save]) saves only on Enter or ✓; the ✓ shows while the value differs, leaving the
    // field keeps text and ✓, Escape restores. Until then such a field is sent with its saved value. The answer's message
    // becomes the toast; DocHamster.on('properties:saved', ({ form, json }) => …) lets the module update other windows.
    const savedByButton = el => el.closest?.('.field-addon')?.querySelector('[data-field-save]') ?? null;
    const markPending = input => {
        const save = savedByButton(input);
        if (save) save.hidden = input.value === input.defaultValue;
    };
    const autosave = (form, field = null) => {
        const body = new FormData(form);
        form.querySelectorAll('.field-addon :is(input, textarea)').forEach(input => {
            if (input !== field && input.name && savedByButton(input)) body.set(input.name, input.defaultValue);
        });
        return post(form.action, body).then(json => {
            if (field) {
                field.defaultValue = field.value;
                markPending(field);
                field.closest('.field-addon').classList.remove('is-error');
                field.removeAttribute('aria-invalid');
            }
            toast(json.message);
            DocHamster.emit('properties:saved', { form, json });
        }).catch(error => {
            if (field) {
                field.closest('.field-addon').classList.add('is-error');
                field.setAttribute('aria-invalid', 'true');
            }
            fail(error);
        });
    };
    document.addEventListener('change', event => {
        const el = event.target;
        const form = el.closest?.('form[data-autosave]');
        // A field that belongs to another form (form="…", e.g. the document's folder select, point 170.1) is not ours.
        if (!form || (!el.name && !el.matches('[data-color-picker]')) || savedByButton(el) || (el.form && el.form !== form)) {
            return;
        }
        if (el.matches('[data-color-hex]') && el.value !== '' && !/^#[0-9a-f]{6}$/i.test(el.value)) {
            return; // not a colour yet
        }
        autosave(form);
    });
    document.addEventListener('input', event => {
        if (event.target.closest?.('form[data-autosave] .field-addon')) {
            markPending(event.target);
            event.target.closest('.field-addon').classList.remove('is-error');
        }
    });
    document.addEventListener('keydown', event => {
        const input = event.target;
        if (!input.closest?.('form[data-autosave]') || !savedByButton(input) || !input.matches('input, textarea')) {
            return;
        }
        if (event.key === 'Enter' && input.matches('input')) {
            event.preventDefault();
            if (input.value !== input.defaultValue) autosave(input.form, input);
        } else if (event.key === 'Escape' && input.value !== input.defaultValue) {
            event.preventDefault();
            event.stopPropagation();
            input.value = input.defaultValue;
            markPending(input);
            input.closest('.field-addon').classList.remove('is-error');
            input.removeAttribute('aria-invalid');
        }
    });
    document.addEventListener('click', event => {
        const save = event.target.closest('form[data-autosave] [data-field-save]');
        const input = save?.closest('.field-addon')?.querySelector('input, textarea');
        if (input) {
            event.preventDefault();
            autosave(save.form, input);
        }
    });
    document.addEventListener('submit', event => {
        if (event.target.matches('form[data-autosave]')) {
            event.preventDefault();
            autosave(event.target);
        }
    });

    // --- Folder of a document saves on change (point 170.1) --------------------------------------------------------
    // select[data-doc-move] in the detail window: moves the document at once (bulk move route, like a drop), toast with
    // "Rückgängig"; every select of that document follows, the folder windows reload.
    document.addEventListener('change', event => {
        const select = event.target.closest?.('select[data-doc-move]');
        const cfg = docs.config();
        if (!select || !cfg.move) {
            return;
        }
        const id = select.dataset.docMove;
        const before = select.dataset.current ?? '';
        const show = folder => document.querySelectorAll(`select[data-doc-move="${CSS.escape(id)}"]`).forEach(each => {
            each.value = folder;
            each.dataset.current = folder;
        });
        post(cfg.move, { ids: [id], folder: select.value }).then(json => {
            show(select.value);
            toast(json.message, {
                undo: before ? () => post(cfg.move, { ids: [id], folder: before }).then(() => {
                    show(before);
                    toast(cfg.undone);
                    docs.refresh();
                }).catch(fail) : null,
            });
            docs.refresh();
        }).catch(error => {
            select.value = before;
            fail(error);
        });
    });

    // --- Field with symbol ("Feld mit Symbol", A195-A199): X clears, the eye shows the password ---------------------
    document.addEventListener('click', event => {
        const clear = event.target.closest('.field-addon [data-field-clear]');
        const reveal = event.target.closest('.field-addon [data-field-reveal]');
        const input = (clear ?? reveal)?.closest('.field-addon').querySelector('input');
        if (!input) {
            return;
        }
        if (clear) {
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        } else {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            reveal.setAttribute('aria-pressed', String(show));
            const label = show ? reveal.dataset.labelHide : reveal.dataset.labelShow;
            if (label) {
                reveal.setAttribute('aria-label', label);
                reveal.dataset.tooltip = label;
            }
        }
    });
    document.addEventListener('input', event => {
        const clear = event.target.closest?.('.field-addon')?.querySelector('[data-field-clear]');
        if (clear) clear.hidden = event.target.value === '';
    });
})();
