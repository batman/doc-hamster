(() => {
    const root = document.documentElement;

    // A small event bus for modules (A74): DocHamster.on('window:open', root => …) runs for the first page and
    // for every window loaded later; 'window:close' before a window goes. Pages without windows count as one.
    const listeners = {};
    const DocHamster = window.DocHamster = {
        on(name, fn) {
            (listeners[name] ??= []).push(fn);
        },
        emit(name, root) {
            (listeners[name] ?? []).forEach(fn => fn(root));
        },
    };
})();
