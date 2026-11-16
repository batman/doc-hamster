(() => {
    // The framework's messages (PO 2026-11-16: moved here from modules/documents.js – every module uses the same):
    // - DocHamster.toast(text, { error, undo, undoLabel }): a toast top right, closes itself (with "Rückgängig" a little
    //   later), a click closes it at once;
    // - DocHamster.confirm({ title, text, ok, danger }): our own question in the framework dialog; resolves true/false.
    //   The yes button is the main button, red (button-danger) only for a delete (danger: true).
    // Texts from <div hidden data-app-texts> in the layout; toasts drawn by the server ([data-toast]) close themselves too.
    const DocHamster = window.DocHamster;
    const texts = () => document.querySelector('[data-app-texts]')?.dataset ?? {};

    const toast = (message, { error = false, undo = null, undoLabel = null } = {}) => {
        if (!message) return;
        const el = document.createElement('div');
        el.className = error ? 'toast toast-error' : 'toast';
        el.setAttribute('role', error ? 'alert' : 'status');
        const textEl = document.createElement('span');
        textEl.textContent = message;
        el.append(textEl);
        const leave = () => {
            el.classList.add('is-leaving');
            setTimeout(() => el.remove(), 300);
        };
        if (undo) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button-quiet toast-action';
            button.textContent = undoLabel ?? texts().undo ?? '';
            button.addEventListener('click', event => {
                event.stopPropagation();
                leave();
                undo();
            }, { once: true });
            el.append(button);
        }
        el.addEventListener('click', leave);
        document.body.append(el);
        setTimeout(leave, undo ? 8000 : 4000);
    };

    const confirm = ({ title, text = '', ok, danger = false }) => new Promise(resolve => {
        const backdrop = document.createElement('div');
        backdrop.className = 'dialog-backdrop';
        const box = document.createElement('div');
        box.className = 'dialog';
        box.setAttribute('role', 'alertdialog');
        box.setAttribute('aria-modal', 'true');
        const h = document.createElement('h2');
        h.textContent = title;
        box.append(h);
        if (text) {
            const p = document.createElement('p');
            p.textContent = text;
            box.append(p);
        }
        const actions = document.createElement('div');
        actions.className = 'dialog-actions';
        const no = document.createElement('button');
        no.type = 'button';
        no.className = 'button-quiet';
        no.textContent = texts().cancel ?? '';
        const yes = document.createElement('button');
        yes.type = 'button';
        if (danger) yes.className = 'button-danger';
        yes.textContent = ok ?? title;
        actions.append(no, yes);
        box.append(actions);
        backdrop.append(box);
        document.body.append(backdrop);
        const before = document.activeElement;
        const close = answer => {
            document.removeEventListener('keydown', key, true);
            backdrop.remove();
            before?.focus?.();
            resolve(answer);
        };
        const key = event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close(false);
            }
        };
        no.addEventListener('click', () => close(false));
        yes.addEventListener('click', () => close(true));
        backdrop.addEventListener('click', event => { if (event.target === backdrop) close(false); });
        document.addEventListener('keydown', key, true);
        no.focus();
    });

    if (DocHamster) {
        DocHamster.toast = toast;
        DocHamster.confirm = confirm;
    }

    // Toasts drawn by the server ([data-toast]) close themselves after a few seconds; a click closes them at once.
    const setupToasts = (root = document) => {
        root.querySelectorAll?.('[data-toast]:not([data-toast-ready])').forEach(el => {
            el.dataset.toastReady = '';
            const leave = () => {
                el.classList.add('is-leaving');
                setTimeout(() => el.remove(), 300);
            };
            el.addEventListener('click', leave);
            setTimeout(leave, 5000);
        });
    };
    document.addEventListener('DOMContentLoaded', () => setupToasts());
    // Also in windows loaded later (a window body with a status from the server).
    DocHamster?.on?.('window:open', root => setupToasts(root instanceof Element ? root : document));
})();
