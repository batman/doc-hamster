/* Recognised text: reading view, word marks, copying (points 121, 122.3, 122.7, 134, 242, A240-A243, A280, A281) */
// The words of the recognised text laid out like on the page (monospace, x gaps as spaces), page by page. Every
// word is a pill linked with the preview above: hover lights both (amounts, IBANs and recognised values as a whole), a
// click opens the word popup (modules/ocr-popup.js); several words form a block (modules/ocr-block.js: Ctrl/Cmd, Shift,
// "+ Wort hinzufügen", a marked area). Point 134: the text is never edited as a block. A280: dragging makes a normal
// text selection; Ctrl+C copies it with line breaks and the Auswahl-Leiste offers "Kopieren" and "Markieren als …".
// A281: "Alles kopieren" in the head. Everything is per window: the same document may be open twice; state lives on
// the reading container. The other files use DocHamster.ocrText.
(() => {
    const DocHamster = window.DocHamster;
    const states = new WeakMap();
    const IBAN = /^DE\d{20}$/;
    const AMOUNT = /^(?:€|EUR)?-?(?:\d{1,3}(?:\.\d{3})+|\d+),\d{2}(?:€|EUR)?$/;
    const COPY_ICON = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 9.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667l0 -8.666"/><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1"/></svg>';
    const UNSURE = 60;
    const make = (tag, className = '', text = null) => {
        const el = document.createElement(tag);
        if (className) el.className = className;
        if (text !== null) el.textContent = text;
        return el;
    };
    const toast = (text, error = false) => { if (text) DocHamster.toast?.(text, { error }); };

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
    const plainOf = read => read.parentElement.querySelector(':scope > .is-plain');
    const pillsOf = (st, refs) => refs.map(ref => st.root.querySelector(`.word-pill[data-p="${ref.p}"][data-i="${ref.i}"]`)).filter(Boolean);
    const wordOf = (st, ref) => st.pages.find(page => page.n === ref.p)?.words[ref.i] ?? null;
    // Reading order of a page's words: by line, then left to right (like the reading view).
    const order = (st, page) => {
        const words = st.pages.find(p => p.n === page)?.words ?? [];
        return words.map((w, i) => i).sort((a, b) => (words[a].l - words[b].l) || (words[a].x - words[b].x));
    };
    const sorted = (st, refs) => {
        if (!refs.length) return refs;
        const at = new Map(order(st, refs[0].p).map((i, n) => [i, n]));
        return [...refs].sort((a, b) => (at.get(a.i) ?? 0) - (at.get(b.i) ?? 0));
    };

    const render = (st, json) => {
        st.pages = json.pages ?? [];
        st.fixes = json.fixes ?? [];
        st.learned = json.learned ?? {};
        st.values = json.values ?? [];
        st.group = new Map();
        const read = st.root;
        read.replaceChildren();
        if (st.pages.length === 0) {
            read.hidden = true;
            const plain = plainOf(read);
            if (plain) plain.hidden = false;
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
                        st.group.set(`${page.n}:${i}`, group.map(j => ({ p: page.n, i: j })));
                        row.append(pill);
                    });
                });
                box.append(row);
            });
            read.append(box);
        });
        const plain = plainOf(read);
        if (plain) plain.hidden = true;
        read.hidden = false;
        paintSelection(st);
    };
    const load = st => fetch(st.root.dataset.ocrRead, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(response => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
        .then(json => render(st, json))
        .catch(() => { /* the plain text stays */ });

    const init = read => {
        if (states.has(read)) return;
        const st = { root: read, win: winOf(read), documentId: read.dataset.document, labels: JSON.parse(read.dataset.labels || '{}'), canEdit: !!read.dataset.fix, pages: [], fixes: [], learned: {}, values: [], group: new Map(), selection: [] };
        states.set(read, st);
        load(st);
    };
    DocHamster.on('window:open', root => root.querySelectorAll?.('[data-ocr-read]').forEach(init));
    // Another window corrected a word or stored a value of the same document: reload.
    const reload = ({ documentId, except } = {}) => {
        document.querySelectorAll(`[data-ocr-read][data-document="${CSS.escape(String(documentId ?? ''))}"]`).forEach(read => {
            const st = states.get(read);
            if (st && read !== except) load(st);
        });
    };
    DocHamster.on('ocr-text:changed', reload);
    DocHamster.on('properties:changed', reload); // A431: the places of the values

    // --- Marks on the preview (A240, document:highlight) ----------------------------------------------------------
    const mark = (frame, box, className) => {
        const page = DocHamster.viewer.layer(frame);
        const el = make('div', 'doc-mark ' + className);
        Object.assign(el.style, { left: box.x * 100 + '%', top: box.y * 100 + '%', width: box.w * 100 + '%', height: box.h * 100 + '%' });
        page.append(el);
        return el;
    };
    const clearMarks = (frame, className) => frame?.querySelectorAll(`.doc-mark.${className}`).forEach(el => el.remove());
    // One mark per line of the words (a block over several lines is outlined line by line, A425).
    const markLines = (frame, st, refs, className) => {
        const lines = new Map();
        refs.map(ref => wordOf(st, ref)).filter(Boolean).forEach(w => {
            if (!lines.has(w.l)) lines.set(w.l, []);
            lines.get(w.l).push(w);
        });
        lines.forEach(words => mark(frame, union(words), className));
    };
    const hoverOn = (st, refs) => {
        hoverOff(st);
        if (!refs.length) return;
        pillsOf(st, refs).forEach(pill => pill.classList.add('is-hover'));
        const frame = frameOf(st);
        if (frame && DocHamster.viewer.page(frame) === refs[0].p) markLines(frame, st, refs, 'is-hover');
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
        if (frame && DocHamster.viewer.page(frame) === st.selection[0].p) markLines(frame, st, st.selection, 'is-selected');
    };
    // A431: the stored value (with a place) whose box holds this word – its words are taken as a whole.
    const valueAt = (st, ref) => {
        const w = wordOf(st, ref);
        return w ? st.values.find(v => v.p === ref.p && inside(w, v)) ?? null : null;
    };
    const wordsIn = (st, page, box) => (st.pages.find(p => p.n === page)?.words ?? [])
        .map((w, i) => ({ w, i })).filter(({ w }) => inside(w, box)).map(({ i }) => ({ p: page, i }));
    const groupOf = (st, ref) => {
        const value = valueAt(st, ref);
        if (value) return sorted(st, wordsIn(st, ref.p, value));
        return st.group.get(`${ref.p}:${ref.i}`) ?? [ref];
    };
    const refOf = pill => ({ p: Number(pill.dataset.p), i: Number(pill.dataset.i) });
    // How a pick changes the block (A425): 'set' = only these, 'toggle' = in or out (Ctrl/Cmd), 'range' = everything from
    // the block's first word to these in reading order (Shift). A block stays on one page; another page starts anew.
    const select = (st, refs, how = 'set') => {
        const same = st.selection.length && st.selection[0].p === refs[0].p;
        if (how === 'toggle' && same) {
            const have = new Set(st.selection.map(ref => ref.i));
            const all = refs.every(ref => have.has(ref.i));
            st.selection = all ? st.selection.filter(ref => !refs.some(r => r.i === ref.i)) : [...st.selection, ...refs.filter(ref => !have.has(ref.i))];
        } else if (how === 'range' && same) {
            const seq = order(st, refs[0].p);
            const ends = [st.selection[0].i, ...refs.map(ref => ref.i)].map(i => seq.indexOf(i));
            st.selection = seq.slice(Math.min(...ends), Math.max(...ends) + 1).map(i => ({ p: refs[0].p, i }));
        } else {
            st.selection = refs;
        }
        st.selection = sorted(st, st.selection);
        paintSelection(st);
    };

    // The pulse of a value with a stored place (point 239 "Im Beleg zeigen", from the Eigenschaften card): to its page,
    // zoomed to it, outlined briefly.
    DocHamster.on('document:highlight', ({ win, page, box } = {}) => {
        const frame = DocHamster.viewer?.frameIn(win);
        if (!frame || !box) return;
        DocHamster.viewer.goTo(frame, Number(page) || 1).then(ok => {
            if (!ok) return;
            clearMarks(frame, 'is-pulse');
            DocHamster.viewer.focus(frame, box);
            const el = mark(frame, box, 'is-pulse');
            frame.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            setTimeout(() => el.remove(), 1800);
        });
    });
    // A419: hovering "Im Beleg zeigen" only outlines the place – when its page is shown; nothing moves.
    DocHamster.on('document:outline', ({ win, page, box, on } = {}) => {
        const frame = DocHamster.viewer?.frameIn(win);
        if (!frame) return;
        clearMarks(frame, 'is-outline');
        if (on && box && DocHamster.viewer.page(frame) === (Number(page) || 1)) mark(frame, box, 'is-outline');
    });
    // A new page in the viewer: the selection of that page shows again.
    DocHamster.on('viewer:page', ({ frame } = {}) => {
        frame?.closest('section[data-window]')?.querySelectorAll('[data-ocr-read]').forEach(read => {
            const st = states.get(read);
            if (st) paintSelection(st);
        });
    });

    // --- Hover in the reading view and in the preview (A240) --------------------------------------------------------
    const stateFor = el => {
        const read = el?.closest?.('[data-ocr-read]');
        return read ? states.get(read) : null;
    };
    document.addEventListener('pointerover', event => {
        const pill = event.target.closest?.('.word-pill');
        const st = stateFor(pill);
        if (!st || event.buttons & 1) return;
        hoverOn(st, groupOf(st, refOf(pill)));
    });
    document.addEventListener('pointerout', event => {
        const pill = event.target.closest?.('.word-pill');
        const st = stateFor(pill);
        if (st && !pill.contains(event.relatedTarget)) hoverOff(st);
    });
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

    // --- Selecting and copying the text (point 134, A280/A281) ------------------------------------------------------
    const readOf = node => (node?.nodeType === 1 ? node : node?.parentElement)?.closest?.('[data-ocr-read]') ?? null;
    const selectionState = () => {
        const sel = document.getSelection();
        if (!sel || sel.isCollapsed || !sel.rangeCount) return null;
        const read = readOf(sel.anchorNode) ?? readOf(sel.focusNode);
        return read ? states.get(read) ?? null : null;
    };
    // The word pills the browser selection touches, in reading order.
    const selectedPills = st => {
        const sel = document.getSelection();
        if (!st || !sel || sel.isCollapsed || !sel.rangeCount || !sel.getRangeAt(0).intersectsNode(st.root)) return [];
        return [...st.root.querySelectorAll('.word-pill')].filter(pill => sel.containsNode(pill, true));
    };
    // Words of a line joined by one space, lines by a line break, pages by a blank line.
    const textOf = pills => {
        const pages = [];
        let page = null, row = null, words = null;
        pills.forEach(pill => {
            if (pill.dataset.p !== page) { page = pill.dataset.p; pages.push([]); row = null; }
            if (pill.parentElement !== row) { row = pill.parentElement; words = []; pages[pages.length - 1].push(words); }
            words.push(pill.textContent);
        });
        return pages.map(lines => lines.map(line => line.join(' ')).join('\n')).join('\n\n');
    };
    const copy = (text, labels) => DocHamster.copyText(text).then(ok => toast(ok ? labels.copied : labels.copy_failed, !ok));
    document.addEventListener('copy', event => {
        const pills = selectedPills(selectionState());
        if (!pills.length || !event.clipboardData) return;
        event.clipboardData.setData('text/plain', textOf(pills));
        event.preventDefault();
    });
    // The Auswahl-Leiste above a selection in the reading view: "Kopieren" and (editors) "Markieren als …" with the
    // selected words of the first page as a block.
    const offerSelection = () => setTimeout(() => {
        const st = selectionState();
        const pills = selectedPills(st);
        if (!pills.length || st.popup) return;
        const L = st.labels;
        const actions = [{ label: L.copy, icon: COPY_ICON, run: () => copy(textOf(pills), L) }];
        if (st.canEdit) {
            actions.push({ label: L.mark_as, run: () => {
                const refs = pills.map(refOf).filter(ref => ref.p === Number(pills[0].dataset.p));
                const words = refs.map(ref => wordOf(st, ref)).filter(Boolean);
                if (!words.length) return;
                DocHamster.markAs?.({ win: st.win, documentId: st.documentId, text: words.map(w => w.t).join(' '), page: refs[0].p, box: union(words), words: refs.map(ref => ref.i), anchor: null });
            } });
        }
        DocHamster.selectionBar?.show(document.getSelection().getRangeAt(0).getBoundingClientRect(), actions);
    }, 0);
    document.addEventListener('pointerup', event => {
        if (!event.target.closest?.('.selection-bar') && (readOf(event.target) || selectionState())) offerSelection();
    });
    document.addEventListener('keyup', event => { if (event.shiftKey || event.key === 'Shift') offerSelection(); });
    // A281: "Alles kopieren" – the whole recognised text with its line breaks.
    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-ocr-copy]');
        const plain = button?.closest('[data-ocr-card], .detail-section')?.querySelector('.is-plain');
        if (!button || !plain) return;
        copy(plain.textContent.replace(/\f/g, '\n\n').trim(), { copied: button.dataset.done, copy_failed: button.dataset.failed });
    });

    DocHamster.ocrText = {
        states, stateFor, previewState, viewerFrame, wordAt, refOf, groupOf, valueAt, wordsIn, select, sorted, paintSelection,
        hoverOff, wordOf, union, inside, make, toast, render, plainOf, pillsOf, frameOf, selectedPills, UNSURE,
        popup: () => {}, // modules/ocr-popup.js
    };
})();
