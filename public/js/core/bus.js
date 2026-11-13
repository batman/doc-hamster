(() => {
    // A small event bus for modules (A74): DocHamster.on('window:open', root => …) runs for the first page and
    // for every window loaded later; 'window:close' before a window goes. Pages without windows count as one.
    const listeners = {};
    const DocHamster = window.DocHamster = {
        on(name, fn) {
            (listeners[name] ??= []).push(fn);
        },
        // One module's error never stops the others or the window system (point 114): it is only logged.
        emit(name, root) {
            (listeners[name] ?? []).forEach(fn => {
                try {
                    fn(root);
                } catch (error) {
                    console.error(`DocHamster ${name}:`, error);
                    DocHamster.reportError?.(`${error?.name ?? 'Error'}: ${error?.message ?? error}`, name);
                }
            });
        },
    };
})();
