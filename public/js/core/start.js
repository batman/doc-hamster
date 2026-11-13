// Pages without the window system: the whole page is the one window (after every core setup above).
(() => {
    document.addEventListener('DOMContentLoaded', () => {
        if (!document.querySelector('[data-windows]')) {
            window.DocHamster.emit('window:open', document);
        }
    });
})();
