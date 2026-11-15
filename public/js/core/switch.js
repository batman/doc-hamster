/* Schalter (switch, points 218, 223; FRAMEWORK.md): an input in .switch with data-confirm-title (and -text, -ok) asks in
   the framework confirm dialog before it is switched on; only a yes lets the change through (e.g. to the autosave form).
   Switching off never asks. Runs in the capture phase, before any module sees the change. */
(() => {
    window.addEventListener('change', event => {
        const input = event.target;
        if (!input.matches?.('.switch > input[data-confirm-title]') || !input.checked || input.dataset.confirmed === '1') {
            return;
        }
        event.stopImmediatePropagation();
        input.checked = false;
        const ask = window.DocHamster?.docs?.confirmDialog;
        const answer = ask
            ? ask({ title: input.dataset.confirmTitle, text: input.dataset.confirmText ?? '', ok: input.dataset.confirmOk ?? input.dataset.confirmTitle })
            : Promise.resolve(window.confirm(input.dataset.confirmTitle));
        answer.then(yes => {
            if (!yes) {
                return;
            }
            input.checked = true;
            input.dataset.confirmed = '1';
            input.dispatchEvent(new Event('change', { bubbles: true }));
            delete input.dataset.confirmed;
        });
    }, true);
})();
