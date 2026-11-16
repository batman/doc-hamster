/* Picking OCR words as one block (point 242, A425-A427, A431; modules/ocr-text.js holds the state) */
// In the reading view and on the preview: a click takes the word (its IBAN/amount group, or the whole recognised value
// it belongs to, A431) and opens the word popup. A425: Ctrl/Cmd+click adds or removes words, Shift+click takes every
// word from the block's first one to the clicked one in reading order; letting the key go opens the popup for the block.
// A426: after "+ Wort hinzufügen" in the popup, the next tap adds that word and the popup comes back. A427: an area
// marked in Markieren mode offers "Markieren als …" for all words inside it.
(() => {
    const DocHamster = window.DocHamster;
    const O = () => DocHamster.ocrText;
    const MODIFIERS = ['Shift', 'Control', 'Meta'];
    const howOf = event => (event.ctrlKey || event.metaKey ? 'toggle' : event.shiftKey ? 'range' : 'set');
    const each = run => document.querySelectorAll('[data-ocr-read]').forEach(read => {
        const st = O().states.get(read);
        if (st) run(st);
    });

    const pick = (st, ref, how) => {
        const refs = O().groupOf(st, ref);
        if (st.adding) {
            st.adding = false;
            O().select(st, refs, 'toggle');
            O().popup(st);
            return;
        }
        O().select(st, refs, how);
        if (how === 'set') O().popup(st); else st.picking = true;
    };

    // Reading view: a click (no drag – a drag selects text, A280) or a modifier click on a word pill.
    document.addEventListener('pointerdown', event => {
        const pill = event.target.closest?.('.word-pill');
        const st = O().stateFor(pill);
        if (!st || !st.canEdit || event.button !== 0) return;
        const ref = O().refOf(pill);
        const how = howOf(event);
        if (how !== 'set') {
            event.preventDefault();
            pick(st, ref, how);
            return;
        }
        const press = { x: event.clientX, y: event.clientY };
        const up = done => {
            document.removeEventListener('pointerup', up);
            if (O().selectedPills(st).length || Math.hypot(done.clientX - press.x, done.clientY - press.y) > 4) return;
            pick(st, ref, 'set');
        };
        document.addEventListener('pointerup', up);
    });

    // Preview: a click (no pan) on a word; not in Markieren mode, not with Alt (box).
    const downs = new WeakMap();
    document.addEventListener('pointerdown', event => {
        const frame = O().viewerFrame(event.target);
        if (frame) downs.set(frame, { x: event.clientX, y: event.clientY });
    }, true);
    document.addEventListener('click', event => {
        const frame = O().viewerFrame(event.target);
        const st = frame && O().previewState(frame);
        const down = frame && downs.get(frame);
        if (!st || !st.canEdit || !down || Math.hypot(event.clientX - down.x, event.clientY - down.y) > 4 || frame.classList.contains('is-selecting') || event.altKey) return;
        const ref = O().wordAt(st, frame, event);
        if (ref) pick(st, ref, howOf(event));
    });

    // Letting Ctrl/Cmd/Shift go ends the pick with the popup for the block.
    document.addEventListener('keyup', event => {
        if (!MODIFIERS.includes(event.key)) return;
        each(st => {
            if (st.picking && st.selection.length && !st.popup) { st.picking = false; O().popup(st); }
        });
    });
    // Escape drops a block being picked and the "+ Wort hinzufügen" mode.
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        each(st => {
            if (!st.picking && !st.adding) return;
            st.picking = false;
            st.adding = false;
            if (!st.popup) { st.selection = []; O().paintSelection(st); }
        });
    });

    // A427: a box marked in the preview ("Markieren"): its words form one block for "Markieren als …".
    DocHamster.on('viewer:box', ({ frame, page, box, add } = {}) => {
        const st = O().previewState(frame);
        const refs = st ? O().sorted(st, O().wordsIn(st, page, box)) : [];
        if (!st?.canEdit || !refs.length) return;
        add(st.labels.mark_as, () => {
            O().select(st, refs, 'set');
            const words = refs.map(ref => O().wordOf(st, ref)).filter(Boolean);
            Promise.resolve(DocHamster.markAs?.({
                win: st.win, documentId: st.documentId, text: words.map(w => w.t).join(' '), page, box: O().union(words), words: refs.map(ref => ref.i), anchor: null,
            })).finally(() => { st.selection = []; O().paintSelection(st); });
        });
    });
})();
