/* Documents: files from the computer (point 85, A169-A175; split from documents.js, A315). Point 181, A358: onto a folder
   row or a folder window they go into that folder; anywhere else on DocHamster (the menu's drop field, the Posteingang,
   any other window, the free desk) they go into the Posteingang without a folder. Other modules' own file drops (bank
   statements, data-statement-drop) and file fields keep theirs. */
(() => {
    const DocHamster = window.DocHamster;
    const docs = DocHamster?.docs;
    if (!docs) {
        return;
    }
    const { html, config, fill, refresh, winOf, folderOfWindow, setTarget } = docs;

    // --- Files from the computer (point 85, A169-A175) -------------------------------------------------------------
    const carriesFiles = event => event.dataTransfer !== null && [...(event.dataTransfer?.types ?? [])].includes('Files');
    const navDrop = () => document.querySelector('[data-nav-drop]');
    const fileTarget = el => {
        const cfg = config();
        if (!cfg.upload || !(el instanceof Element) || el.closest('[data-statement-drop], input[type="file"]')) {
            return null;
        }
        const field = navDrop();
        const inbox = field ? { el: field, folder: '', name: cfg.inboxName } : null;
        if (el.closest('.side')) {
            return inbox;
        }
        const row = el.closest('[data-folder-row]:not([data-drop-trash])');
        if (row) {
            return { el: row, folder: row.dataset.id, name: row.dataset.name };
        }
        const win = winOf(el);
        const body = win?.querySelector('.win-body');
        if (!win || !body || win.classList.contains('is-min')) {
            return inbox; // the free desk: the menu's drop field shows where it goes
        }
        const folder = win.dataset.windowKind === 'folder' && !body.querySelector('[data-no-drop]') ? folderOfWindow(win) : null;
        return folder ? { el: body, folder, name: win.dataset.windowTitle } : { el: body, folder: '', name: cfg.inboxName };
    };
    // A folder window's body shows the same words as a folder row: "Loslassen zum Hochladen in „X“" (A170, A175).
    const uploadLabel = target => {
        if (target.el.matches('.win-body')) {
            target.el.dataset.uploadLabel = fill(config().dropUpload, { name: target.name });
        }
    };
    let fileDepth = 0;
    const fileEnd = () => {
        fileDepth = 0;
        html.classList.remove('is-file-drag');
        const field = navDrop();
        if (field) {
            field.hidden = true;
        }
        setTarget(null);
    };
    document.addEventListener('dragenter', event => {
        if (!carriesFiles(event) || !config().upload) {
            return;
        }
        fileDepth++;
        html.classList.add('is-file-drag');
        const field = navDrop();
        if (field) {
            field.hidden = false;
        }
    });
    document.addEventListener('dragleave', event => {
        if (carriesFiles(event) && --fileDepth <= 0) {
            fileEnd();
        }
    });
    document.addEventListener('dragover', event => {
        if (!carriesFiles(event)) {
            return;
        }
        event.preventDefault(); // never let the browser open the file itself
        const target = fileTarget(event.target);
        event.dataTransfer.dropEffect = target ? 'copy' : 'none';
        if (target) {
            uploadLabel(target);
        }
        setTarget(target?.el ?? null, true);
    });
    document.addEventListener('drop', event => {
        if (!carriesFiles(event) && [...(event.dataTransfer?.types ?? [])].includes('application/x-dochamster-preview')) {
            event.preventDefault();
            return;
        }
        if (!carriesFiles(event)) {
            return;
        }
        event.preventDefault();
        const target = fileTarget(event.target);
        const files = [...event.dataTransfer.files];
        fileEnd();
        if (!target || files.length === 0) {
            return;
        }
        upload(files, target);
    });
    // Points 27–29: through the Hochlade-Dialog (core/upload.js) – progress, rest time, protocol; the windows follow.
    const upload = (files, target) => {
        const cfg = config();
        DocHamster.uploadFiles(files, { url: cfg.upload, folder: target.folder, name: target.name, after: refresh })
            .finally(() => {
                refresh();
                DocHamster.refreshBadges?.();
            });
    };
})();
