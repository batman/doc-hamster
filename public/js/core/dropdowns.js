(() => {
    // Dropdowns (filter, view, "⋯"): one listener for the whole page, so they work in every window, also in
    // windows loaded later and several side by side (Go 2026-11-07, point 26, A77). The icon button opens its
    // panel; a click outside, a click on an entry and Escape close it.
    const setupDropdowns = () => {
        const setOpen = (dropdown, open) => {
            dropdown.querySelector(':scope > .dropdown-panel').hidden = !open;
            dropdown.querySelector(':scope > .icon-button').setAttribute('aria-expanded', String(open));
        };
        const openOnes = () => [...document.querySelectorAll('.dropdown')].filter(d => d.querySelector(':scope > .dropdown-panel')?.hidden === false);
        document.addEventListener('click', event => {
            const toggle = event.target.closest('.dropdown > .icon-button');
            const inside = event.target.closest('.dropdown');
            openOnes().filter(d => d !== inside && !d.contains(inside)).forEach(d => setOpen(d, false));
            if (toggle) {
                const dropdown = toggle.parentElement;
                setOpen(dropdown, dropdown.querySelector(':scope > .dropdown-panel').hidden);
            } else if (inside && event.target.closest('.dropdown-panel a, .dropdown-panel .menu-item')) {
                setOpen(inside, false);
            }
        });
        document.addEventListener('keydown', event => {
            const open = openOnes().pop();
            if (event.key === 'Escape' && open) {
                setOpen(open, false);
                open.querySelector(':scope > .icon-button').focus();
            }
        });
    };

    document.addEventListener('DOMContentLoaded', setupDropdowns);
})();
