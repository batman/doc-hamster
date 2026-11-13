(() => {
    // Tabs (A93): arrow keys, Home and End move between the tabs of a bar.
    document.addEventListener('keydown', event => {
        const tab = event.target.closest?.('.tab-bar .tab');
        if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            return;
        }
        const tabs = [...tab.parentElement.querySelectorAll('.tab')];
        const at = tabs.indexOf(tab);
        const to = { ArrowLeft: at - 1, ArrowRight: at + 1, Home: 0, End: tabs.length - 1 }[event.key];
        event.preventDefault();
        tabs[(to + tabs.length) % tabs.length].focus();
    });
})();
