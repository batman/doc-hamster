// Foldable card ("Karte, klappbar", point 125, A260/A261): the chevron in a card head folds the body away. The server
// draws the first state (page default or the cookie dh_folds), so nothing jumps; a click here toggles – softly (point 23,
// A72, A73) – and remembers it per card id (page.card) in that cookie. Delegated, so cards in windows loaded later work too.
(() => {
    const COOKIE = 'dh_folds';
    const MAX = 3000;

    const read = () => {
        try {
            const hit = document.cookie.match(/(?:^|;\s*)dh_folds=([^;]*)/);
            return hit ? decodeURIComponent(hit[1]).split('|').filter(Boolean) : [];
        } catch {
            return [];
        }
    };
    const remember = (key, folded) => {
        try {
            const list = read().filter(entry => entry.slice(0, entry.lastIndexOf(':')) !== key);
            list.push(`${key}:${folded ? 1 : 0}`);
            while (list.length > 1 && list.join('|').length > MAX) list.shift();
            document.cookie = `${COOKIE}=${encodeURIComponent(list.join('|'))}; path=/; max-age=31536000; SameSite=Lax`;
        } catch { /* only a convenience */ }
    };

    // Point 23, A72, A73 (PO's values from the prototype): opening first opens the empty card, then its items fade in
    // one after the other and slide a few pixels into place; folding first fades them out, then closes the card.
    // Never linear: a quick start, a soft end. "Reduce motion" in the system: no animation at all.
    const TIME = { open: 500, fadeIn: 1500, stagger: 200, fadeOut: 500, close: 500 };
    const MAX_STAGGERED = 8; // a long list: from the ninth item on they come together, so it never drags on
    const EASE_OUT = 'cubic-bezier(.2, .8, .2, 1)';
    const EASE_CLOSE = 'cubic-bezier(.4, 0, .2, 1)';
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    const runs = new WeakMap();
    // The items that fade: the rows of a body that is one list (a menu group), else the body's own children.
    const itemsOf = body => {
        const kids = [...body.children];
        return kids.length === 1 && kids[0].matches('ul, ol') ? [...kids[0].children] : kids;
    };
    const animate = (card, body, folded) => {
        runs.get(card)?.forEach(run => run.cancel());
        const list = [];
        runs.set(card, list);
        const run = (el, frames, options) => {
            const animation = el.animate(frames, { fill: 'both', ...options });
            list.push(animation);
            return animation.finished;
        };
        const items = itemsOf(body);
        const done = () => {
            if (runs.get(card) !== list) return; // a quicker click took over
            list.forEach(animation => animation.cancel());
            body.style.overflow = '';
        };
        body.style.overflow = 'hidden';
        if (!folded) {
            body.hidden = false;
            const height = `${body.scrollHeight}px`;
            const margin = getComputedStyle(body).marginTop;
            items.forEach(item => run(item, [{ opacity: 0 }, { opacity: 0 }], { duration: TIME.open }));
            run(body, [{ height: '0px', marginTop: '0px' }, { height, marginTop: margin }], { duration: TIME.open, easing: EASE_OUT })
                .then(() => Promise.all(items.map((item, n) => run(item, [{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'none' }],
                    { duration: TIME.fadeIn, delay: Math.min(n, MAX_STAGGERED) * TIME.stagger, easing: EASE_OUT }))))
                .catch(() => {}).finally(done);
            return;
        }
        Promise.all(items.map(item => run(item, [{ opacity: 1 }, { opacity: 0 }], { duration: TIME.fadeOut, easing: EASE_OUT })))
            .then(() => run(body, [{ height: `${body.offsetHeight}px`, marginTop: getComputedStyle(body).marginTop }, { height: '0px', marginTop: '0px' }],
                { duration: TIME.close, easing: EASE_CLOSE }))
            .then(() => { if (runs.get(card) === list) body.hidden = true; })
            .catch(() => {}).finally(done);
    };

    const set = (card, folded, smooth = false) => {
        const button = card.querySelector(':scope > .card-head > [data-card-fold-toggle]');
        const body = card.querySelector(':scope > .card-body');
        card.classList.toggle('is-folded', folded);
        if (body && smooth && !reduced.matches && typeof body.animate === 'function') {
            animate(card, body, folded);
        } else if (body) {
            runs.get(card)?.forEach(run => run.cancel());
            body.style.overflow = '';
            body.hidden = folded;
        }
        if (button) {
            const label = folded ? button.dataset.labelUnfold : button.dataset.labelFold;
            button.setAttribute('aria-expanded', folded ? 'false' : 'true');
            button.setAttribute('aria-label', label ?? '');
            button.dataset.tooltip = label ?? '';
        }
        window.DocHamster?.emit?.('card:fold', { card, folded });
    };

    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-card-fold-toggle]');
        const card = button?.closest('[data-card-fold]');
        if (!card) return;
        const folded = !card.classList.contains('is-folded');
        set(card, folded, true);
        if (!card.hasAttribute('data-card-fold-forget')) remember(card.dataset.cardFold, folded);
    });

    if (window.DocHamster) window.DocHamster.foldCard = set;
})();
