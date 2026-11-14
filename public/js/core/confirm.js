/* Bestätigung (Sudo), point 212: sensitive actions need a confirmation from the last five minutes (middleware
   `confirmed`). Three ways in, one dialog ([data-confirm-sudo-dialog], layouts/app):
   - a form with data-confirm-sudo asks before it is sent (status check first), then is sent again with its button;
   - any fetch answered 423 + X-DocHamster-Confirm asks, then the same request runs again;
   - a plain post that came back unconfirmed opens the dialog on load (data-confirm-open) and says to run it again.
   DocHamster.confirmSudo() → Promise<boolean> lets a module ask itself. */
(() => {
    const DocHamster = window.DocHamster;
    let until = 0;
    let pending = null;
    const box = () => document.querySelector('[data-confirm-sudo-dialog]');

    const ask = () => {
        const dialog = box();
        if (!dialog) {
            return Promise.resolve(false);
        }
        if (pending) {
            return pending;
        }
        pending = new Promise(resolve => {
            const form = dialog.querySelector('form');
            const field = form.querySelector('input[type="password"]');
            const error = form.querySelector('[data-confirm-error]');
            const button = form.querySelector('button[type="submit"]');
            let settled = false;
            const finish = ok => {
                if (settled) return;
                settled = true;
                watch.disconnect();
                form.removeEventListener('submit', send);
                dialog.hidden = true;
                pending = null;
                resolve(ok);
            };
            const failed = text => {
                error.textContent = text || form.dataset.failed;
                error.hidden = false;
                field.closest('.field')?.classList.add('has-error');
                field.select();
            };
            const send = event => {
                event.preventDefault();
                event.stopPropagation();
                button.disabled = true;
                button.classList.add('is-busy');
                fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } })
                    .then(response => response.json().catch(() => ({})).then(json => {
                        if (response.ok && json.confirmed) {
                            until = Date.now() + (json.seconds - 10) * 1000;
                            finish(true);
                        } else {
                            failed(json.errors?.password?.[0] ?? json.message);
                        }
                    }))
                    .catch(() => failed())
                    .finally(() => {
                        button.disabled = false;
                        button.classList.remove('is-busy');
                    });
            };
            // Closed by X, backdrop or Escape (core/dialogs.js sets hidden): the action does not go on.
            const watch = new MutationObserver(() => dialog.hidden && finish(false));
            form.reset();
            error.hidden = true;
            field.closest('.field')?.classList.remove('has-error');
            form.addEventListener('submit', send);
            dialog.hidden = false;
            watch.observe(dialog, { attributes: true, attributeFilter: ['hidden'] });
            field.focus();
        });
        return pending;
    };

    const fresh = () => {
        const url = box()?.dataset.status;
        if (!url) {
            return Promise.resolve(true);
        }
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(response => (response.ok ? response.json() : { fresh: false }))
            .then(json => {
                if (json.fresh) {
                    until = Date.now() + (json.seconds - 10) * 1000;
                }
                return Boolean(json.fresh);
            })
            .catch(() => false);
    };

    DocHamster.confirmSudo = () => (Date.now() < until ? Promise.resolve(true) : fresh().then(ok => ok || ask()));

    // Forms marked data-confirm-sudo: asked before anything else sees the submit (capture), then sent again.
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm-sudo') || Date.now() < until) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter?.form === form ? event.submitter : null;
        DocHamster.confirmSudo().then(ok => {
            if (ok) {
                form.requestSubmit(submitter);
            }
        });
    }, true);

    // Every fetch: a 423 from the middleware asks and runs the same request again.
    const plainFetch = window.fetch;
    window.fetch = (input, init = {}) => {
        const again = input instanceof Request ? input.clone() : input;
        return plainFetch(input, init).then(response => {
            if (response.status !== 423 || response.headers.get('X-DocHamster-Confirm') !== 'required') {
                return response;
            }
            until = 0;
            return ask().then(ok => (ok ? window.fetch(again, init) : response));
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        const dialog = box();
        if (dialog?.hasAttribute('data-confirm-open')) {
            ask().then(ok => ok && DocHamster.toast?.(dialog.dataset.again));
        }
    });
})();
