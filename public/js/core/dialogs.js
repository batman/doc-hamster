(() => {
    const DocHamster = window.DocHamster;
    const root = document.documentElement;
    // Our own confirm dialog: [data-dialog-open="x"] shows [data-dialog="x"]; Escape, the backdrop and "Abbrechen" close it.
    const setupDialogs = () => {
        const shut = dialog => { dialog.hidden = true; };
        // Point 48 / A36: every dialog closes with an X at the top right instead of "Abbrechen".
        const addX = root => (root.matches?.('.dialog') ? [root] : [...root.querySelectorAll('[data-dialog] .dialog, .dialog-backdrop .dialog')]).forEach(box => {
            if (box.querySelector(':scope > .dialog-x') || box.closest('[data-update-progress], .update-progress')) return;
            const x = document.createElement('button');
            x.type = 'button';
            x.className = 'icon-button button-small dialog-x';
            x.setAttribute('data-dialog-close', '');
            x.setAttribute('aria-label', document.documentElement.lang === 'en' ? 'Close' : 'Schließen');
            x.innerHTML = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>';
            box.prepend(x);
        });
        addX(document);
        DocHamster.on('window:open', root => addX(root));
        const open = button => {
            const name = `[data-dialog="${button.dataset.dialogOpen}"]`;
            const dialog = button.closest('section[data-window]')?.querySelector(name) ?? document.querySelector(name);
            const heading = dialog.querySelector('[data-dialog-heading]');
            if (heading && button.dataset.dialogTitle) {
                heading.textContent = button.dataset.dialogTitle;
            }
            addX(dialog);
            dialog.hidden = false;
            dialog.querySelector('input:not([type="hidden"]), select, textarea, button:not([data-dialog-close]), a, [data-dialog-close]')?.focus();
        };
        // The file is fetched ahead: the share menu has to open right on the click. Per window, also for later ones.
        const shared = new Map();
        DocHamster.on('window:open', root => root.querySelectorAll('[data-share]').forEach(button => {
            if (!navigator.canShare) {
                return;
            }
            fetch(button.dataset.share, { credentials: 'same-origin' })
                .then(response => (response.ok ? response.blob() : null))
                .then(blob => {
                    const file = blob && new File([blob], button.dataset.shareName, { type: button.dataset.shareType });
                    if (file && navigator.canShare({ files: [file] })) {
                        shared.set(button, file);
                    }
                })
                .catch(() => { /* no share menu then, our dialog stays */ });
        }));
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-dialog-open]');
            if (!button || button.disabled) {
                return;
            }
            const file = shared.get(button);
            if (file) {
                navigator.share({ files: [file], title: file.name }).catch(error => {
                    if (error.name !== 'AbortError') {
                        open(button);
                    }
                });
                return;
            }
            open(button);
        });
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-copy]');
            if (!button) {
                return;
            }
            // Kopierfeld (points 152, A329): the symbol button inside a field copies its value and toasts; a button with
            // text (the share link) shows the "copied" text instead. The field is found next to the button or by its id.
            const field = button.closest('.field-addon')?.querySelector('input, textarea') ?? document.getElementById(button.dataset.copy);
            if (!field) {
                return;
            }
            field.select();
            const copy = DocHamster.copyText ? DocHamster.copyText(field.value) : navigator.clipboard?.writeText(field.value).then(() => true);
            copy?.then(ok => {
                if (ok === false) return;
                if (button.classList.contains('addon-button')) {
                    DocHamster.toast?.(button.dataset.copied);
                } else {
                    button.textContent = button.dataset.copied;
                }
            });
        });
        // Checklists save with every tick.
        document.addEventListener('change', event => event.target.closest('form[data-autosubmit]')?.submit());
        document.addEventListener('click', event => {
            const dialog = event.target.closest('[data-dialog]');
            if (dialog && (event.target === dialog || event.target.closest('[data-dialog-close]'))) {
                shut(dialog);
            }
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                document.querySelectorAll('[data-dialog]:not([hidden])').forEach(shut);
            }
        });
    };

    document.addEventListener('DOMContentLoaded', setupDialogs);
})();
