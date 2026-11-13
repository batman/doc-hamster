(() => {
    // Receipt filter: ticks collect, "Anwenden" filters. A receipt must have all ticked tags. Each filter works
    // on the list of its own window. When the window doesn't hold every receipt, the server filters instead.
    const setupFilter = () => {
        const apply = filter => {
            if (filter.dataset.complete !== '1') {
                filter.submit();
                return;
            }
            const scope = filter.closest('section[data-window]') ?? document;
            const wanted = [...filter.querySelectorAll('input[name="tags[]"]')].filter(box => box.checked).map(box => box.value);
            let shown = 0;
            scope.querySelectorAll('[data-item]').forEach(item => {
                const has = item.dataset.tags.split(' ');
                item.hidden = !wanted.every(tag => has.includes(tag));
                shown += item.hidden ? 0 : 1;
            });
            scope.querySelectorAll('[data-group]').forEach(group => {
                group.hidden = group.querySelector('[data-item]:not([hidden])') === null;
            });
            const none = scope.querySelector('[data-no-match]');
            if (none) {
                none.hidden = shown > 0 || wanted.length === 0;
            }

            const active = wanted.length > 0;
            filter.querySelector('.icon-button').classList.toggle('is-active', active);
            filter.querySelector('[data-icon="off"]').hidden = active;
            filter.querySelector('[data-icon="on"]').hidden = !active;
            const badge = filter.querySelector('.badge');
            badge.textContent = String(wanted.length);
            badge.hidden = !active;

            // Keep the window's address in step, so reloading or a shared link shows the same.
            const url = new URL(scope.dataset?.windowUrl ?? location.href, location.href);
            url.searchParams.delete('tags[]');
            wanted.forEach(tag => url.searchParams.append('tags[]', tag));
            if (scope !== document) {
                scope.dataset.windowUrl = url.href;
            }
            if (scope === document || scope.classList.contains('is-active')) {
                history.replaceState({ window: url.href, id: scope.dataset?.windowId }, '', url);
            }
        };
        const close = filter => {
            filter.querySelector('.dropdown-panel').hidden = true;
            filter.querySelector('.icon-button').setAttribute('aria-expanded', 'false');
        };
        document.addEventListener('submit', event => {
            const filter = event.target.closest('.dropdown[data-kind="filter"]');
            if (filter && filter.dataset.complete === '1') {
                event.preventDefault();
                apply(filter);
                close(filter);
            }
        });
        document.addEventListener('click', event => {
            const reset = event.target.closest('.dropdown[data-kind="filter"] [data-reset]');
            if (reset) {
                const filter = reset.closest('.dropdown');
                filter.querySelectorAll('input[name="tags[]"]').forEach(box => { box.checked = false; });
                apply(filter);
                close(filter);
            }
        });
    };

    document.addEventListener('DOMContentLoaded', setupFilter);
})();
