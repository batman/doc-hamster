/* Documents: forms of the Papierkorb, asked first and sent in the background; split from documents.js (A315). */
(() => {
    const DocHamster = window.DocHamster;
    const docs = DocHamster?.docs;
    if (!docs) {
        return;
    }
    const { post, toast, fail, confirmDialog } = docs;
    // The Papierkorb's toolbar follows too: "Papierkorb leeren" and the ⋯ entries "Leerung rückgängig machen" (point 207).
    const refresh = () => {
        docs.refresh();
        document.querySelectorAll('[data-windows] > section[data-window][data-window-kind="trash"]').forEach(win => {
            DocHamster.reloadWindow(win, new URL(win.dataset.windowUrl ?? location.href, location.href), '.win-tools').catch(() => {});
        });
    };

    // --- Forms of the Papierkorb (restore, delete for good, "Papierkorb leeren" and its undo): asked first, sent in the background
    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-bulk-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const send = () => post(form.action, new FormData(form)).then(json => {
            form.closest('[data-dialog]')?.querySelector('[data-dialog-close]')?.click();
            // Point 207: "Papierkorb leeren" answers how to undo it – the toast offers "Rückgängig machen".
            const undo = json.undo ? () => post(json.undo, { batch: json.batch }).then(back => {
                toast(back.message);
                refresh();
            }).catch(fail) : null;
            toast(json.message, { undo, undoLabel: json.undoLabel });
            refresh();
        }).catch(fail);
        if (form.dataset.confirm) {
            confirmDialog({ title: form.dataset.confirmTitle ?? '', text: form.dataset.confirm, ok: form.dataset.confirmOk ?? '' }).then(yes => yes && send());
        } else {
            send();
        }
    });
})();
