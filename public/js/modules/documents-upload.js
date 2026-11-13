/* Documents: files from the computer onto a folder row, a folder window or the menu's drop field (point 85, A169-A175);
   split from documents.js (A315). */
(() => {
    const DocHamster = window.DocHamster;
    const docs = DocHamster?.docs;
    if (!docs) {
        return;
    }
    const { html, config, fill, toast, post, fail, refresh, winOf, folderOfWindow, setTarget } = docs;

    // --- Files from the computer (point 85, A169-A175) -------------------------------------------------------------
    const carriesFiles = event => event.dataTransfer !== null && [...(event.dataTransfer?.types ?? [])].includes('Files');
    const navDrop = () => document.querySelector('[data-nav-drop]');
    const fileTarget = el => {
        const cfg = config();
        if (!cfg.upload || !(el instanceof Element)) {
            return null;
        }
        if (el.closest('.side')) {
            const field = navDrop();
            return field ? { el: field, folder: cfg.defaultFolder, name: cfg.defaultName } : null;
        }
        const row = el.closest('[data-folder-row]');
        if (row) {
            return row.hasAttribute('data-drop-trash') ? null : { el: row, folder: row.dataset.id, name: row.dataset.name };
        }
        const win = winOf(el);
        const body = el.closest('.win-body');
        if (win && body && win.dataset.windowKind === 'folder' && !win.classList.contains('is-min') && !body.querySelector('[data-no-drop]')) {
            const folder = folderOfWindow(win);
            const name = folder ? win.dataset.windowTitle : cfg.defaultName;
            return { el: body, folder: folder ?? cfg.defaultFolder, name };
        }
        return null;
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
    const upload = (files, target) => {
        const cfg = config();
        const chunks = [];
        for (let i = 0; i < files.length; i += 20) {
            chunks.push(files.slice(i, i + 20));
        }
        const busy = document.querySelector('.nav-upload');
        busy?.classList.add('is-busy');
        let saved = 0;
        chunks.reduce((chain, chunk) => chain.then(() => {
            const body = new FormData();
            chunk.forEach(file => body.append('files[]', file));
            body.append('folder', target.folder);
            return post(cfg.upload, body).then(() => { saved += chunk.length; });
        }), Promise.resolve())
            .then(() => {
                toast(fill(saved === 1 ? cfg.uploadedOne : cfg.uploadedMany, { count: saved, name: target.name }));
            })
            .catch(fail)
            .finally(() => {
                busy?.classList.remove('is-busy');
                refresh();
            });
    };
})();
