(() => {
    // Menu badges: the numbers come again about once a minute while the page is visible.
    const setupBadges = () => {
        const side = document.querySelector('[data-badges]');
        if (!side) {
            return;
        }
        const show = counts => {
            document.querySelectorAll('[data-badge]').forEach(badge => {
                const count = counts[badge.dataset.badge] ?? 0;
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.hidden = count === 0;
            });
            // A68: a folded menu group shows the sum of its entries' counts (data-badge-sum="key key").
            document.querySelectorAll('[data-badge-sum]').forEach(badge => {
                const count = badge.dataset.badgeSum.split(' ').reduce((sum, key) => sum + (counts[key] ?? 0), 0);
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.hidden = count === 0;
            });
            side.querySelector('[data-badge-any]').hidden = Object.values(counts).every(count => !count);
        };
        const poll = () => {
            if (document.hidden) {
                return;
            }
            fetch(side.dataset.badges, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(response => (response.ok ? response.json() : null))
                .then(counts => counts && show(counts))
                .catch(() => { /* offline or logged out: keep what is shown */ });
        };
        setInterval(poll, 60000);
        // Point 149: a module that just changed what a badge counts asks for the numbers at once.
        if (window.DocHamster) {
            window.DocHamster.refreshBadges = poll;
        }
        document.addEventListener('visibilitychange', poll);
    };

    document.addEventListener('DOMContentLoaded', setupBadges);
})();
