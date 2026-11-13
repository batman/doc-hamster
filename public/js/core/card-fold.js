// Foldable card ("Karte, klappbar", point 125, A260/A261): the chevron in a card head folds the body away. The server
// draws the first state (page default or the cookie dh_folds), so nothing jumps; a click here toggles and remembers it
// per card id (page.card) in that cookie. Delegated, so cards in windows loaded later work too.
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

    const set = (card, folded) => {
        const button = card.querySelector(':scope > .card-head > [data-card-fold-toggle]');
        const body = card.querySelector(':scope > .card-body');
        card.classList.toggle('is-folded', folded);
        if (body) body.hidden = folded;
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
        set(card, folded);
        if (!card.hasAttribute('data-card-fold-forget')) remember(card.dataset.cardFold, folded);
    });

    if (window.DocHamster) window.DocHamster.foldCard = set;
})();
