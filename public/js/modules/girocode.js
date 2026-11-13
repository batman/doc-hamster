/* GiroCode card (points 120, 120.4, A237-A239, A247, A249, A250; documents/_girocode.blade.php) */
// Everything is delegated and starts from the card ([data-girocode]): the same document may be open in two windows,
// each card works on its own. Values are saved by click-to-edit (core/edit-in-place.js) through the property routes;
// afterwards every card and Eigenschaften-Karte of the document reloads (properties:changed).
(() => {
    const DocHamster = window.DocHamster;
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const cardOf = el => el?.closest?.('[data-girocode]') ?? null;
    const toast = (text, error = false) => {
        if (text && DocHamster.toast) {
            DocHamster.toast(text, { error });
        }
    };
    const post = (url, body) => fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' } })
        .then(async response => {
            const json = await response.json().catch(() => ({}));
            if (!response.ok) {
                const errors = json.errors ?? {};
                throw new Error(errors.value?.[0] ?? errors.property?.[0] ?? json.message ?? '');
            }
            return json;
        });
    // Per card: the typing timer, the drawn code's blob address, the latest redraw.
    const timers = new WeakMap();
    const urls = new WeakMap();
    const runs = new WeakMap();
    const changed = card => DocHamster.emit('properties:changed', { win: card.closest('section[data-window]'), documentId: card.dataset.document });

    // --- Reload: after any saved value of this document, in every window ---------------------------------------
    DocHamster.on('properties:changed', ({ documentId } = {}) => {
        document.querySelectorAll(`[data-girocode][data-document="${CSS.escape(String(documentId ?? ''))}"]`).forEach(card => {
            fetch(card.dataset.girocode, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(response => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
                .then(json => {
                    const next = document.createRange().createContextualFragment(json.html).firstElementChild;
                    if (next && card.isConnected) {
                        if (urls.has(card)) URL.revokeObjectURL(urls.get(card));
                        card.replaceWith(next);
                    }
                })
                .catch(() => { /* the card stays as it was */ });
        });
    });
    DocHamster.on('edit-in-place:saved', ({ box } = {}) => {
        const card = cardOf(box);
        if (card) changed(card);
    });

    // --- Live code while typing (debounced): the fields as they are now, drawn by the server, nothing stored ----
    const query = card => {
        const q = new URLSearchParams();
        card.querySelectorAll('[data-giro-param]').forEach(row => {
            const field = row.querySelector('.edit-in-place-field');
            const box = row.querySelector('[data-edit-in-place]');
            q.set(row.dataset.giroParam, (field ? field.value : (box?.textContent ?? '')).trim());
        });
        if (card.dataset.skonto === '1') {
            q.set('skonto', '1');
        }
        return q;
    };
    const redraw = card => {
        const figure = card.querySelector('[data-giro-qr]');
        const img = card.querySelector('[data-giro-img]');
        const missing = card.querySelector('[data-giro-missing]');
        if (!figure || !img) {
            return;
        }
        const run = (runs.get(card) ?? 0) + 1;
        runs.set(card, run);
        figure.classList.add('is-stale');
        fetch(`${card.dataset.svg}?${query(card)}`, { credentials: 'same-origin' })
            .then(response => (response.ok ? response.blob() : Promise.reject(new Error(String(response.status)))))
            .then(blob => {
                if (runs.get(card) !== run) return;
                const old = urls.get(card);
                const url = URL.createObjectURL(blob);
                urls.set(card, url);
                img.src = url;
                if (old) URL.revokeObjectURL(old);
                figure.hidden = false;
                figure.classList.remove('is-stale');
                if (missing) missing.hidden = true;
            })
            .catch(() => { /* incomplete for now: the code stays faded until the fields fit */ });
    };
    document.addEventListener('input', event => {
        const field = event.target.closest?.('.edit-in-place-field');
        const card = cardOf(field);
        if (!card || !field.closest('[data-giro-param]')) {
            return;
        }
        clearTimeout(timers.get(card));
        timers.set(card, setTimeout(() => redraw(card), 350));
    });

    // --- Skonto (A237): full amount or the amount with Skonto in the code ------------------------------------------
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-giro-skonto]');
        const card = cardOf(button);
        if (!card) {
            return;
        }
        card.dataset.skonto = button.dataset.giroSkonto;
        button.parentElement.querySelectorAll('[data-giro-skonto]').forEach(b => b.setAttribute('aria-pressed', String(b === button)));
        const pay = card.querySelector('[data-giro-pay]');
        if (pay) {
            pay.textContent = card.dataset.skonto === '1' ? pay.dataset.reduced : pay.dataset.full;
        }
        redraw(card);
    });

    // --- Chips (120.4, A247): one click takes another amount or the repaired IBAN -----------------------------------
    document.addEventListener('click', event => {
        const chip = event.target.closest('[data-giro-take]');
        const card = cardOf(chip);
        if (!card) {
            return;
        }
        const body = new FormData();
        body.append('property', chip.dataset.giroTake);
        body.append('value', chip.dataset.value ?? '');
        if (chip.dataset.page && chip.dataset.box) {
            body.append('page', chip.dataset.page);
            try {
                const box = JSON.parse(chip.dataset.box);
                ['x', 'y', 'w', 'h'].forEach(part => body.append(`box[${part}]`, String(box[part] ?? '')));
            } catch { /* without a place */ }
        }
        chip.disabled = true;
        post(card.dataset.set, body).then(json => {
            toast(json.message);
            changed(card);
        }).catch(error => {
            chip.disabled = false;
            toast(error.message || card.dataset.failed, true);
        });
    });
    // A chip with a place shows it in the reading view while hovered or focused.
    const show = event => {
        const chip = event.target.closest?.('.suggest-chip[data-page][data-box]');
        if (!chip || !cardOf(chip)) {
            return;
        }
        try {
            DocHamster.emit('document:highlight', { win: chip.closest('section[data-window]'), page: Number(chip.dataset.page), box: JSON.parse(chip.dataset.box) });
        } catch { /* a broken box draws nothing */ }
    };
    document.addEventListener('mouseover', show);
    document.addEventListener('focusin', show);

    // --- Als bezahlt markieren / Rückgängig (A238, A249) --------------------------------------------------------
    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-giro-action]');
        const card = cardOf(form);
        if (!form || !card) {
            return;
        }
        event.preventDefault();
        const button = form.querySelector('button');
        if (button) button.disabled = true;
        post(form.action, new FormData(form)).then(json => {
            toast(json.message);
            changed(card);
        }).catch(error => {
            if (button) button.disabled = false;
            toast(error.message || card.dataset.failed, true);
        });
    });
})();
