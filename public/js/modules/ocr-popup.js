/* The word popup of the recognised text (points 122.3, 122.7, 242; split from modules/ocr-text.js) */
// One word or a block of words (A428): an enlarged crop over all of them, the text (joined, editable), notes,
// "Übernehmen" (a single word's OCR correction) and "Markieren als …" for the whole block. A426: "+ Wort hinzufügen"
// lets the next tap on the document add a word (phones). A431: a block that is a recognised value names it.
(() => {
    const DocHamster = window.DocHamster;
    const O = () => DocHamster.ocrText;
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const DATE = /^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/;
    const AMOUNT = /^(?:€|EUR)?-?(?:\d{1,3}(?:\.\d{3})+|\d+),\d{2}(?:€|EUR)?$/;
    const X_ICON = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>';
    const PLUS_ICON = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5l0 14"/><path d="M5 12l14 0"/></svg>';
    const fill = (template, values) => Object.entries(values).reduce((out, [key, value]) => out.replace(':' + key, value), template);

    // --- Type hints (122.7): IBAN with mod-97, amount, date --------------------------------------------------------
    const ibanOk = iban => {
        const moved = iban.slice(4) + iban.slice(0, 4);
        let rest = 0;
        for (const ch of moved) {
            const part = /\d/.test(ch) ? ch : String(ch.charCodeAt(0) - 55);
            for (const digit of part) rest = (rest * 10 + Number(digit)) % 97;
        }
        return rest === 1;
    };
    const kind = raw => {
        const text = raw.replace(/\s+/g, '');
        if (/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/i.test(text)) return { ok: ibanOk(text.toUpperCase()), key: ibanOk(text.toUpperCase()) ? 'iban_ok' : 'iban_bad' };
        if (AMOUNT.test(text)) return { ok: true, key: 'amount', value: text.replace(/€|EUR/g, '') + ' €' };
        const date = text.match(DATE);
        if (date) {
            const year = date[3].length === 2 ? 2000 + Number(date[3]) : Number(date[3]);
            const d = new Date(year, Number(date[2]) - 1, Number(date[1]));
            const ok = d.getFullYear() === year && d.getMonth() === Number(date[2]) - 1 && d.getDate() === Number(date[1]);
            return { ok, key: 'date', value: `${String(date[1]).padStart(2, '0')}.${String(date[2]).padStart(2, '0')}.${year}` };
        }
        return null;
    };

    // Crop: the words' area, a bit larger, drawn from the shown page into a small canvas; each line of the block framed.
    const crop = (st, refs, page) => {
        const frame = O().frameOf(st);
        const words = refs.map(ref => O().wordOf(st, ref)).filter(Boolean);
        const box = O().union(words);
        if (!frame) return Promise.resolve(null);
        return DocHamster.viewer.goTo(frame, page).then(ok => {
            const src = ok ? DocHamster.viewer.source(frame) : null;
            if (!src) return null;
            const padY = Math.min(box.h, 0.03) * 0.8, padX = Math.max(padY * 1.9, box.w * 0.1);
            const x = Math.max(0, box.x - padX) * src.w, y = Math.max(0, box.y - padY) * src.h;
            const w = Math.min(1, box.x + box.w + padX) * src.w - x, h = Math.min(1, box.y + box.h + padY) * src.h - y;
            if (w < 2 || h < 2) return null;
            const ratio = window.devicePixelRatio || 1;
            const cssW = Math.min(416, Math.max(160, w * 3)), cssH = Math.min(160, cssW * h / w);
            const canvas = O().make('canvas', 'word-crop');
            canvas.width = Math.round(cssW * ratio);
            canvas.height = Math.round(cssH * ratio);
            canvas.style.aspectRatio = `${w} / ${h}`;
            const ctx = canvas.getContext('2d');
            try { ctx.drawImage(src.img, x, y, w, h, 0, 0, canvas.width, canvas.height); } catch { return null; }
            ctx.strokeStyle = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#ff7622';
            ctx.lineWidth = 2 * ratio;
            const sx = canvas.width / w, sy = canvas.height / h;
            const lines = new Map();
            words.forEach(word => { if (!lines.has(word.l)) lines.set(word.l, []); lines.get(word.l).push(word); });
            lines.forEach(line => {
                const b = O().union(line);
                ctx.strokeRect((b.x * src.w - x) * sx, (b.y * src.h - y) * sy, b.w * src.w * sx, b.h * src.h * sy);
            });
            return canvas;
        });
    };
    const post = (url, body) => fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' } })
        .then(async response => {
            const json = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(json.errors?.box?.[0] ?? json.errors?.text?.[0] ?? json.message ?? '');
            return json;
        });
    const placeBody = (page, box) => {
        const body = new FormData();
        body.append('page', String(page));
        ['x', 'y', 'w', 'h'].forEach(part => body.append(`box[${part}]`, String(box[part])));
        return body;
    };
    const applied = (st, json) => {
        O().render(st, json);
        const plain = O().plainOf(st.root);
        if (plain && typeof json.text === 'string') plain.textContent = json.text;
        O().toast(json.message);
        DocHamster.emit('ocr-text:changed', { documentId: st.documentId, except: st.root });
    };

    let counter = 0;
    const popup = st => {
        if (!st.selection.length || st.popup) return;
        const { make } = O();
        const L = st.labels;
        const refs = st.selection;
        const page = refs[0].p;
        const words = refs.map(ref => O().wordOf(st, ref)).filter(Boolean);
        if (!words.length) return;
        const box = O().union(words);
        const single = refs.length === 1 ? refs[0] : null;
        const pill = single ? O().pillsOf(st, [single])[0] : null;
        const text = words.map(w => w.t).join(' ');
        // A431: the block is exactly a recognised value.
        const value = O().valueAt(st, refs[0]);
        const isValue = value && O().wordsIn(st, page, value).length === refs.length && refs.every(ref => O().valueAt(st, ref) === value);
        const name = `word-popup-${Date.now().toString(36)}-${++counter}`;

        const backdrop = make('div', 'dialog-backdrop');
        backdrop.dataset.dialog = name;
        backdrop.hidden = true;
        const dialog = make('div', 'dialog word-popup');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', `${name}-title`);
        const close = make('button', 'icon-button button-small dialog-x');
        close.type = 'button';
        close.dataset.dialogClose = '';
        close.setAttribute('aria-label', L.close);
        close.innerHTML = X_ICON;
        const title = make('h2', '', single ? L.word : L.selection);
        title.id = `${name}-title`;
        const cropBox = make('div', 'word-crop-box');
        cropBox.hidden = true;
        dialog.append(close, title, cropBox);
        crop(st, refs, page).then(canvas => { if (canvas) { cropBox.append(canvas); cropBox.hidden = false; } });

        const form = make('form', 'word-popup-form');
        form.noValidate = true;
        const field = make('div', 'field');
        const label = make('label', '', L.recognized);
        label.htmlFor = `${name}-text`;
        const addon = make('div', 'field-addon');
        const input = make('input');
        input.id = `${name}-text`;
        input.value = text;
        input.autocomplete = 'off';
        input.spellcheck = false;
        const end = make('span', 'addon-end');
        const state = make('span', 'addon-state');
        state.setAttribute('role', 'img');
        state.hidden = true;
        end.append(state);
        addon.append(input, end);
        const hint = make('p', 'field-hint');
        hint.setAttribute('aria-live', 'polite');
        field.append(label, addon, hint);
        // A426: the next tap on the document (preview or reading view) adds a word to this block.
        const more = make('button', 'button-quiet button-small');
        more.type = 'button';
        more.innerHTML = PLUS_ICON;
        more.append(L.add_word);
        more.addEventListener('click', () => {
            st.keep = true;
            st.adding = true;
            backdrop.hidden = true;
            O().toast(L.add_hint);
        });
        form.append(field, more);

        // Low confidence, a stored correction (undo), a correction learned from this sender (A241, A243), a value (A431).
        const notes = make('div', 'word-popup-notes');
        if (isValue) {
            const row = make('p', 'word-popup-note');
            row.append(make('span', 'pill', value.name), make('span', '', value.text));
            notes.append(row);
        }
        if (single && words[0].c < O().UNSURE && !pill?.classList.contains('is-fixed')) {
            notes.append(make('span', 'pill pill-warning', L.unsure));
        }
        if (pill?.classList.contains('is-fixed')) {
            const row = make('p', 'word-popup-note');
            row.append(make('span', 'pill', L.corrected), make('span', '', fill(L.original, { text: pill.dataset.original })));
            const undo = make('button', 'button-quiet button-small', L.show_original);
            undo.type = 'button';
            undo.addEventListener('click', () => {
                undo.disabled = true;
                post(st.root.dataset.unfix, placeBody(page, words[0])).then(json => { applied(st, json); backdrop.hidden = true; })
                    .catch(error => { O().toast(error.message || L.no_word, true); undo.disabled = false; });
            });
            row.append(undo);
            notes.append(row);
        } else if (single && Object.hasOwn(st.learned, words[0].t)) {
            const suggestion = st.learned[words[0].t];
            const row = make('p', 'word-popup-note');
            row.append(make('span', '', fill(L.learned, { text: suggestion })));
            const take = make('button', 'button-quiet button-small', L.use_learned);
            take.type = 'button';
            take.addEventListener('click', () => { input.value = suggestion; check(); input.focus(); });
            row.append(take);
            notes.append(row);
        }
        if (notes.childElementCount) form.append(notes);

        const actions = make('div', 'dialog-actions');
        const apply = make('button', 'button-quiet', L.apply);
        apply.type = 'button';
        apply.hidden = true;
        const markAs = make('button', '', L.mark_as);
        markAs.type = 'submit';
        actions.append(apply, markAs);
        form.append(actions);
        dialog.append(form);
        backdrop.append(dialog);

        const check = () => {
            const k = kind(input.value);
            addon.classList.toggle('is-ok', k?.ok === true);
            addon.classList.toggle('is-error', k?.ok === false);
            field.classList.toggle('has-error', k?.ok === false);
            state.hidden = !k;
            hint.textContent = k ? fill(L[k.key], { value: k.value ?? '' }) : '';
            state.setAttribute('aria-label', hint.textContent);
            // A correction is stored per word; a block's edited text only goes into the value.
            apply.hidden = !single || input.value.trim() === '' || input.value.trim() === text;
        };
        input.addEventListener('input', check);
        check();
        // "Übernehmen" stores the correction; "Markieren als …" stores it first when the text was changed.
        const fixFirst = () => (apply.hidden ? Promise.resolve(input.value.trim() || text) : post(st.root.dataset.fix, (() => {
            const body = placeBody(page, words[0]);
            body.append('text', input.value.trim());
            return body;
        })()).then(json => { applied(st, json); return input.value.trim(); }));
        const show = error => {
            addon.classList.add('is-error');
            field.classList.add('has-error');
            hint.textContent = error.message || L.no_word;
        };
        apply.addEventListener('click', () => {
            apply.disabled = true;
            fixFirst().then(() => { backdrop.hidden = true; }).catch(error => { show(error); apply.disabled = false; });
        });
        form.addEventListener('submit', event => {
            event.preventDefault();
            markAs.disabled = true;
            fixFirst().then(chosen => {
                backdrop.hidden = true;
                // A428/A430: the whole block with its combined box; A429 may grow it on the server.
                DocHamster.markAs?.({ win: st.win, documentId: st.documentId, text: chosen, page, box, words: refs.map(ref => ref.i), anchor: null });
            }).catch(error => { show(error); markAs.disabled = false; });
        });

        st.popup = backdrop;
        new MutationObserver(() => {
            if (!backdrop.hidden) return;
            backdrop.remove();
            st.popup = null;
            if (st.keep) { st.keep = false; return; } // "+ Wort hinzufügen": the block stays marked
            st.selection = [];
            O().paintSelection(st);
            O().hoverOff(st);
        }).observe(backdrop, { attributes: true, attributeFilter: ['hidden'] });
        (st.win ?? document.body).append(backdrop);
        backdrop.hidden = false;
        (single ? input : markAs).focus();
        if (single) input.select();
    };
    O().popup = popup;
})();
