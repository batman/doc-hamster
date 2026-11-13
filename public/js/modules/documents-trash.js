/* Documents: forms of the Papierkorb, asked first and sent in the background; split from documents.js (A315). */
(() => {
    const DocHamster = window.DocHamster;
    const docs = DocHamster?.docs;
    if (!docs) {
        return;
    }
    const { post, toast, fail, refresh, confirmDialog } = docs;

    // --- Forms of the Papierkorb (restore, delete for good, "Papierkorb leeren"): asked first, sent in the background ----
    document.addEventListener('submit', event => {
        const form = event.target.closest('form[data-bulk-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        const send = () => post(form.action, new FormData(form)).then(json => {
            form.closest('[data-dialog]')?.querySelector('[data-dialog-close]')?.click();
            toast(json.message);
            refresh();
        }).catch(fail);
        if (form.dataset.confirm) {
            confirmDialog({ title: form.dataset.confirmTitle ?? '', text: form.dataset.confirm, ok: form.dataset.confirmOk ?? '' }).then(yes => yes && send());
        } else {
            send();
        }
    });
})();
