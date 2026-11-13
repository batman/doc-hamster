/* Recognised text: reading view, word popup, OCR corrections (points 121, 122.3, 122.7, A240-A243) */
// The words of the recognised text laid out like on the page (monospace, x gaps as spaces), page by page. Every
// word is a pill linked with the preview above: hover lights both (amounts and IBANs as a whole group), a click (or
// shift-click / drag for several) opens the word popup with an enlarged crop, the correction field and
// "Markieren als …". A click beside the words edits the plain text as before. Everything is per window: the same
// document may be open twice; state lives on the reading container.
(() => {
    const DocHamster = window.DocHamster;
    const states = new WeakMap();
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const UNSURE = 60;
    const IBAN = /^DE\d{20}$/;
    const AMOUNT = /^(?:€|EUR)?-?(?:\d{1,3}(?:\.\d{3})+|\d+),\d{2}(?:€|EUR)?$/;
    const DATE = /^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/;
    const X_ICON = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>';
    const make = (tag, className = '', text = null) => {
        const el = document.createElement(tag);
        if (className) el.className = className;
        if (text !== null) el.textContent = text;
        return el;
    };
    const toast = (text, error = false) => { if (text) DocHamster.toast?.(text, { error }); };
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
        if (AMOUNT.test(text)) {
            const number = text.replace(/€|EUR/g, '');
            return { ok: true, key: 'amount', value: number + ' €' };
        }
        const date = text.match(DATE);
        if (date) {
            const year = date[3].length === 2 ? 2000 + Number(date[3]) : Number(date[3]);
            const d = new Date(year, Number(date[2]) - 1, Number(date[1]));
            const ok = d.getFullYear() === year && d.getMonth() === Number(date[2]) - 1 && d.getDate() === Number(date[1]);
            return { ok, key: 'date', value: `${String(date[1]).padStart(2, '0')}.${String(date[2]).padStart(2, '0')}.${year}` };
        }
        return null;
    };

    // --- Layout (121.1): lines by l, sorted by x, gaps in the page's median character width -------------------------
    const layout = words => {
        const widths = [];
        let left = 1;
        words.forEach(w => {
            if (w.t.length && w.w > 0) widths.push(w.w / w.t.length);
            left = Math.min(left, w.x);
        });
        widths.sort((a, b) => a - b);
        const char = Math.max(widths.length ? widths[Math.floor(widths.length / 2)] : 0.01, 0.001);
        const lines = new Map();
        words.forEach((w, i) => {
            if (!lines.has(w.l)) lines.set(w.l, []);
            lines.get(w.l).push(i);
        });
        return [...lines.values()].map(line => {
            line.sort((a, b) => words[a].x - words[b].x);
            let end = null;
            return line.map(i => {
                const w = words[i];
                const gap = end === null ? Math.round((w.x - left) / char) : Math.max(1, Math.round((w.x - end) / char));
                end = w.x + w.w;
                return { i, gap: Math.min(120, Math.max(0, gap)) };
            });
        });
    };
    // A242: neighbouring words of a line that form one IBAN or amount are one group (longest run wins).
    const groups = (words, line) => {
        const out = [];
        for (let s = 0; s < line.length;) {
            let best = s;
            for (let e = s; e < Math.min(line.length, s + 7); e++) {
                const joined = line.slice(s, e + 1).map(item => words[item.i].t).join('');
                if (IBAN.test(joined) || AMOUNT.test(joined)) best = e;
            }
            out.push(line.slice(s, best + 1).map(item => item.i));
            s = best + 1;
        }
        return out;
    };
    const union = boxes => {
        const x = Math.min(...boxes.map(b => b.x)), y = Math.min(...boxes.map(b => b.y));
        const r = Math.max(...boxes.map(b => b.x + b.w)), b2 = Math.max(...boxes.map(b => b.y + b.h));
        const round = v => Math.round(v * 10000) / 10000;
        return { x: round(x), y: round(y), w: round(r - x), h: round(b2 - y) };
    };
    const inside = (word, box) => {
        const cx = word.x + word.w / 2, cy = word.y + word.h / 2;
        return cx >= box.x && cx <= box.x + box.w && cy >= box.y && cy <= box.y + box.h;
    };

    // --- State per reading container -------------------------------------------------------------------------------
    const winOf = el => el.closest('section[data-window]');
    const frameOf = st => DocHamster.viewer?.frameIn(st.win) ?? null;
    const plainOf = read => read.parentElement.querySelector(':scope > [data-edit-in-place][data-field="ocr_text"], :scope > .is-plain');
    const pillsOf = (st, refs) => refs.map(ref => st.root.querySelector(`.word-pill[data-p="${ref.p}"][data-i="${ref.i}"]`)).filter(Boolean);
    const wordOf = (st, ref) => st.pages.find(page => page.n === ref.p)?.words[ref.i] ?? null;

    const render = (st, json) => {
        st.pages = json.pages ?? [];
        st.fixes = json.fixes ?? [];
        st.learned = json.learned ?? {};
        st.group = new Map();
        const read = st.root;
        read.replaceChildren();
        if (st.pages.length === 0) {
            read.hidden = true;
            const plain = plainOf(read);
            if (plain && !st.editing) plain.hidden = false;
            return;
        }
        st.pages.forEach((page, pi) => {
            if (pi > 0) read.append(make('hr', 'ocr-page-break'));
            const box = make('div', 'ocr-page');
            box.dataset.page = page.n;
            layout(page.words).forEach(line => {
                const row = make('div', 'ocr-line');
                const at = new Map(line.map(item => [item.i, item.gap]));
                groups(page.words, line).forEach(group => {
                    const key = `${page.n}:${group[0]}`;
                    group.forEach(i => {
                        const w = page.words[i];
                        row.append(document.createTextNode(' '.repeat(at.get(i))));
                        const pill = make('span', 'word-pill', w.t);
                        pill.dataset.p = page.n;
                        pill.dataset.i = i;
                        const fix = st.fixes.find(f => f.p === page.n && inside(w, f));
                        if (fix) {
                            pill.classList.add('is-fixed');
                            pill.dataset.original = fix.o;
                        } else if (Object.hasOwn(st.learned, w.t)) {
                            pill.classList.add('is-learned');
                        } else if (w.c < UNSURE) {
                            pill.classList.add('is-unsure');
                        }
                        st.group.set(`${page.n}:${i}`, { key, refs: group.map(j => ({ p: page.n, i: j })) });
                        row.append(pill);
                    });
                });
                box.append(row);
            });
            read.append(box);
        });
        const plain = plainOf(read);
        if (plain) plain.hidden = true;
        read.hidden = st.editing;
        paintSelection(st);
    };
    const load = st => fetch(st.root.dataset.ocrRead, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(response => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
        .then(json => render(st, json))
        .catch(() => { /* the plain text stays */ });

    const init = read => {
        if (states.has(read)) return;
        const st = { root: read, win: winOf(read), documentId: read.dataset.document, labels: JSON.parse(read.dataset.labels || '{}'), canEdit: !!read.dataset.fix, pages: [], fixes: [], learned: {}, group: new Map(), selection: [], editing: false };
        states.set(read, st);
        load(st);
    };
    DocHamster.on('window:open', root => root.querySelectorAll?.('[data-ocr-read]').forEach(init));
    // Another window corrected a word of the same document: reload.
    DocHamster.on('ocr-text:changed', ({ documentId, except } = {}) => {
        document.querySelectorAll(`[data-ocr-read][data-document="${CSS.escape(String(documentId ?? ''))}"]`).forEach(read => {
            const st = states.get(read);
            if (st && read !== except) load(st);
        });
    });

    // --- Marks on the preview (A240, document:highlight) ----------------------------------------------------------
    const mark = (frame, box, className) => {
        const page = DocHamster.viewer.layer(frame);
        const el = make('div', 'doc-mark ' + className);
        Object.assign(el.style, { left: box.x * 100 + '%', top: box.y * 100 + '%', width: box.w * 100 + '%', height: box.h * 100 + '%' });
        page.append(el);
        return el;
    };
    const clearMarks = (frame, className) => frame?.querySelectorAll(`.doc-mark.${className}`).forEach(el => el.remove());
    const hoverOn = (st, refs) => {
        hoverOff(st);
        if (!refs.length) return;
        pillsOf(st, refs).forEach(pill => pill.classList.add('is-hover'));
        const frame = frameOf(st);
        if (frame && DocHamster.viewer.page(frame) === refs[0].p) {
            mark(frame, union(refs.map(ref => wordOf(st, ref)).filter(Boolean)), 'is-hover');
        }
    };
    const hoverOff = st => {
        st.root.querySelectorAll('.word-pill.is-hover').forEach(pill => pill.classList.remove('is-hover'));
        clearMarks(frameOf(st), 'is-hover');
    };
    const paintSelection = st => {
        st.root.querySelectorAll('.word-pill.is-selected').forEach(pill => pill.classList.remove('is-selected'));
        const frame = frameOf(st);
        clearMarks(frame, 'is-selected');
        if (!st.selection.length) return;
        pillsOf(st, st.selection).forEach(pill => pill.classList.add('is-selected'));
        const page = st.selection[0].p;
        if (frame && DocHamster.viewer.page(frame) === page) {
            mark(frame, union(st.selection.map(ref => wordOf(st, ref)).filter(Boolean)), 'is-selected');
        }
    };
    const groupOf = (st, ref) => st.group.get(`${ref.p}:${ref.i}`)?.refs ?? [ref];
    const refOf = pill => ({ p: Number(pill.dataset.p), i: Number(pill.dataset.i) });
    // Shift adds a word (group) to the selection; several words stay on one page.
    const select = (st, refs, add) => {
        if (add && st.selection.length && st.selection[0].p === refs[0].p) {
            const have = new Set(st.selection.map(ref => ref.i));
            st.selection = [...st.selection, ...refs.filter(ref => !have.has(ref.i))];
        } else {
            st.selection = refs;
        }
        paintSelection(st);
    };

    // The pulse of a value with a stored place (point 122.4, from the Eigenschaften card).
    DocHamster.on('document:highlight', ({ win, page, box } = {}) => {
        const frame = DocHamster.viewer?.frameIn(win);
        if (!frame || !box) return;
        DocHamster.viewer.goTo(frame, Number(page) || 1).then(ok => {
            if (!ok) return;
            clearMarks(frame, 'is-pulse');
            const el = mark(frame, box, 'is-pulse');
            DocHamster.viewer.reveal(frame, box);
            frame.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            setTimeout(() => el.remove(), 1800);
        });
    });
    // A new page in the viewer: the selection of that page shows again.
    DocHamster.on('viewer:page', ({ frame } = {}) => {
        frame?.closest('section[data-window]')?.querySelectorAll('[data-ocr-read]').forEach(read => {
            const st = states.get(read);
            if (st) paintSelection(st);
        });
    });
    // A box marked in the preview ("Markieren"): its words can be picked as one value.
    DocHamster.on('viewer:box', ({ frame, page, box, add } = {}) => {
        const read = frame.closest('section[data-window]')?.querySelector('[data-ocr-read]');
        const st = read && states.get(read);
        const words = st?.pages.find(p => p.n === page)?.words ?? [];
        const refs = words.map((w, i) => ({ w, i })).filter(({ w }) => inside(w, box)).map(({ i }) => ({ p: page, i }));
        if (st?.canEdit && refs.length) {
            add(st.labels.select, () => { select(st, refs, false); popup(st, null); });
        }
    });

    // --- Hover and pick in the reading view ------------------------------------------------------------------------
    const stateFor = el => {
        const read = el?.closest?.('[data-ocr-read]');
        return read ? states.get(read) : null;
    };
    document.addEventListener('pointerover', event => {
        const pill = event.target.closest?.('.word-pill');
        const st = stateFor(pill);
        if (!st) return;
        const ref = refOf(pill);
        if (st.drag && event.buttons & 1) {
            // Drag: every word between the first and this one (reading view order, one page).
            const all = [...st.root.querySelectorAll(`.word-pill[data-p="${st.drag.p}"]`)];
            const a = all.findIndex(el => Number(el.dataset.i) === st.drag.i), b = all.indexOf(pill);
            if (a >= 0 && b >= 0) {
                st.drag.moved = st.drag.moved || a !== b;
                st.selection = all.slice(Math.min(a, b), Math.max(a, b) + 1).map(refOf);
                paintSelection(st);
            }
        }
        hoverOn(st, groupOf(st, ref));
    });
    document.addEventListener('pointerout', event => {
        const pill = event.target.closest?.('.word-pill');
        const st = stateFor(pill);
        if (st && !pill.contains(event.relatedTarget)) hoverOff(st);
    });
    document.addEventListener('pointerdown', event => {
        const pill = event.target.closest?.('.word-pill');
        const st = stateFor(pill);
        if (!st || !st.canEdit || event.button !== 0) return;
        event.preventDefault();
        const ref = refOf(pill);
        st.drag = { ...ref, moved: false, shift: event.shiftKey };
        select(st, groupOf(st, ref), event.shiftKey);
        const up = () => {
            document.removeEventListener('pointerup', up);
            const drag = st.drag;
            st.drag = null;
            if (drag && !drag.shift) popup(st, pill);
        };
        document.addEventListener('pointerup', up);
    });
    // 121.3: a click into the box beside the words edits the plain text (the searchable text).
    document.addEventListener('click', event => {
        const read = event.target.closest?.('[data-ocr-read]');
        const st = read && states.get(read);
        if (!st || !st.canEdit || event.target.closest('.word-pill')) return;
        const plain = plainOf(read);
        if (!plain?.matches('[data-edit-in-place]')) return;
        st.editing = true;
        read.hidden = true;
        plain.hidden = false;
        plain.click();
        // Back to reading when the field is done (edit-in-place shows its box again).
        const back = new MutationObserver(() => {
            if (!plain.hidden && !plain.nextElementSibling?.matches('.edit-in-place-field')) {
                back.disconnect();
                st.editing = false;
                if (st.pages.length) { plain.hidden = true; read.hidden = false; }
            }
        });
        back.observe(plain, { attributes: true, attributeFilter: ['hidden'] });
    });
    // Shift-pick ends with the popup when shift is let go.
    document.addEventListener('keyup', event => {
        if (event.key !== 'Shift') return;
        document.querySelectorAll('[data-ocr-read]').forEach(read => {
            const st = states.get(read);
            if (st?.selection.length && !st.popup && st.picking) { st.picking = false; popup(st, null); }
        });
    });

    // --- Hover and pick in the preview (A240 reverse) ---------------------------------------------------------------
    const previewState = frame => {
        const read = frame?.closest('section[data-window]')?.querySelector('[data-ocr-read]');
        return read ? states.get(read) : null;
    };
    const wordAt = (st, frame, event) => {
        const pt = DocHamster.viewer.pointAt(frame, event);
        const page = DocHamster.viewer.page(frame);
        const words = st.pages.find(p => p.n === page)?.words ?? [];
        if (!pt) return null;
        const pad = 0.003;
        const i = words.findIndex(w => pt.x >= w.x - pad && pt.x <= w.x + w.w + pad && pt.y >= w.y - pad && pt.y <= w.y + w.h + pad);
        return i >= 0 ? { p: page, i } : null;
    };
    const viewerFrame = el => {
        const frame = el?.closest?.('.detail-doc');
        return frame && DocHamster.viewer?.frameIn(frame.closest('section[data-window]')) === frame ? frame : null;
    };
    document.addEventListener('pointermove', event => {
        const frame = viewerFrame(event.target);
        const st = frame && previewState(frame);
        if (!st || !st.pages.length || event.buttons) return;
        const ref = wordAt(st, frame, event);
        const key = ref ? `${ref.p}:${ref.i}` : '';
        if (key === st.over) return;
        st.over = key;
        frame.classList.toggle('is-over-word', !!ref);
        if (ref) hoverOn(st, groupOf(st, ref)); else hoverOff(st);
    });
    document.addEventListener('pointerleave', event => {
        const frame = event.target?.matches?.('.detail-doc') ? event.target : null;
        const st = frame && previewState(frame);
        if (st) { st.over = ''; frame.classList.remove('is-over-word'); hoverOff(st); }
    }, true);
    const downs = new WeakMap();
    document.addEventListener('pointerdown', event => {
        const frame = viewerFrame(event.target);
        if (frame) downs.set(frame, { x: event.clientX, y: event.clientY });
    }, true);
    // A click (no pan) on a word opens the popup – a moment later, so a double click (back to fit) does not.
    document.addEventListener('click', event => {
        const frame = viewerFrame(event.target);
        const st = frame && previewState(frame);
        const down = frame && downs.get(frame);
        if (!st || !st.canEdit || !down || Math.hypot(event.clientX - down.x, event.clientY - down.y) > 4 || frame.classList.contains('is-selecting') || event.altKey) return;
        const ref = wordAt(st, frame, event);
        if (!ref) return;
        clearTimeout(st.clickTimer);
        select(st, groupOf(st, ref), event.shiftKey);
        if (event.shiftKey) { st.picking = true; return; }
        st.clickTimer = setTimeout(() => popup(st, null), 250);
    });
    document.addEventListener('dblclick', event => {
        const st = previewState(viewerFrame(event.target));
        if (st) clearTimeout(st.clickTimer);
    });
    // Shift-clicks in the reading view collect too; letting go of shift opens the popup.
    document.addEventListener('pointerdown', event => {
        const st = stateFor(event.target.closest?.('.word-pill'));
        if (st && event.shiftKey) st.picking = true;
    });

    // --- The word popup (122.3, 122.7) ---------------------------------------------------------------------------
    // Crop: the words' area, a bit larger, drawn from the shown page into a small canvas.
    const crop = (st, box, page) => {
        const frame = frameOf(st);
        if (!frame) return Promise.resolve(null);
        return DocHamster.viewer.goTo(frame, page).then(ok => {
            const src = ok ? DocHamster.viewer.source(frame) : null;
            if (!src) return null;
            const padY = box.h * 0.8, padX = Math.max(box.h * 1.5, box.w * 0.1);
            const x = Math.max(0, box.x - padX) * src.w, y = Math.max(0, box.y - padY) * src.h;
            const w = Math.min(1, box.x + box.w + padX) * src.w - x, h = Math.min(1, box.y + box.h + padY) * src.h - y;
            if (w < 2 || h < 2) return null;
            const ratio = window.devicePixelRatio || 1;
            const cssW = Math.min(416, Math.max(160, w * 3)), cssH = Math.min(160, cssW * h / w);
            const canvas = make('canvas', 'word-crop');
            canvas.width = Math.round(cssW * ratio);
            canvas.height = Math.round(cssH * ratio);
            canvas.style.aspectRatio = `${w} / ${h}`;
            const ctx = canvas.getContext('2d');
            try { ctx.drawImage(src.img, x, y, w, h, 0, 0, canvas.width, canvas.height); } catch { return null; }
            // The word itself framed in orange.
            ctx.strokeStyle = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#ff7622';
            ctx.lineWidth = 2 * ratio;
            const sx = canvas.width / w, sy = canvas.height / h;
            ctx.strokeRect((box.x * src.w - x) * sx, (box.y * src.h - y) * sy, box.w * src.w * sx, box.h * src.h * sy);
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
        render(st, json);
        const plain = plainOf(st.root);
        if (plain && typeof json.text === 'string') plain.textContent = json.text;
        toast(json.message);
        DocHamster.emit('ocr-text:changed', { documentId: st.documentId, except: st.root });
    };

    let counter = 0;
    const popup = (st, anchor) => {
        if (!st.selection.length || st.popup) return;
        const L = st.labels;
        const refs = st.selection;
        const page = refs[0].p;
        const words = refs.map(ref => wordOf(st, ref)).filter(Boolean);
        if (!words.length) return;
        const box = union(words);
        const single = refs.length === 1 ? refs[0] : null;
        const pill = single ? pillsOf(st, [single])[0] : null;
        const text = words.map(w => w.t).join(' ');
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
        crop(st, box, page).then(canvas => { if (canvas) { cropBox.append(canvas); cropBox.hidden = false; } });

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
        input.readOnly = !single;
        const end = make('span', 'addon-end');
        const state = make('span', 'addon-state');
        state.setAttribute('role', 'img');
        state.hidden = true;
        end.append(state);
        addon.append(input, end);
        const hint = make('p', 'field-hint');
        hint.setAttribute('aria-live', 'polite');
        field.append(label, addon, hint);
        form.append(field);

        // Low confidence, a stored correction (undo), a correction learned from this sender (A241, A243).
        const notes = make('div', 'word-popup-notes');
        if (single && words[0].c < UNSURE && !pill?.classList.contains('is-fixed')) {
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
                    .catch(error => { toast(error.message || L.no_word, true); undo.disabled = false; });
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
            apply.hidden = !single || input.value.trim() === '' || input.value.trim() === text;
        };
        input.addEventListener('input', check);
        check();
        // "Übernehmen" stores the correction; "Markieren als …" stores it first when the text was changed.
        const fixFirst = () => (apply.hidden ? Promise.resolve(text) : post(st.root.dataset.fix, (() => {
            const body = placeBody(page, words[0]);
            body.append('text', input.value.trim());
            return body;
        })()).then(json => { applied(st, json); return input.value.trim(); }));
        apply.addEventListener('click', () => {
            apply.disabled = true;
            fixFirst().then(() => { backdrop.hidden = true; }).catch(error => { show(error); apply.disabled = false; });
        });
        const show = error => {
            addon.classList.add('is-error');
            field.classList.add('has-error');
            hint.textContent = error.message || L.no_word;
        };
        form.addEventListener('submit', event => {
            event.preventDefault();
            markAs.disabled = true;
            fixFirst().then(value => {
                backdrop.hidden = true;
                DocHamster.markAs?.({ win: st.win, documentId: st.documentId, text: value, page, box, anchor });
            }).catch(error => { show(error); markAs.disabled = false; });
        });

        st.popup = backdrop;
        new MutationObserver(() => {
            if (backdrop.hidden) {
                backdrop.remove();
                st.popup = null;
                st.selection = [];
                paintSelection(st);
                hoverOff(st);
            }
        }).observe(backdrop, { attributes: true, attributeFilter: ['hidden'] });
        (st.win ?? document.body).append(backdrop);
        backdrop.hidden = false;
        (single ? input : markAs).focus();
        if (single) input.select();
    };
})();
