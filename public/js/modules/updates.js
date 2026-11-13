// Update page: the install runs in a pop-up with a progress bar instead of on install.php's own page.
// Without JavaScript, and whenever something fails before the run starts, the browser goes the old way
// (form post, then install.php's own pages). The bar follows install.php's progress file, not
// install.php itself (that request holds the installer's session for the whole run).
(() => { const setupUpdateProgress = () => {
    const box = document.querySelector('[data-update-progress]');
    if (!box || !window.fetch) {
        return;
    }
    const steps = JSON.parse(box.dataset.steps || '{}');
    const bar = box.querySelector('[data-progress-bar]');
    const stepText = box.querySelector('[data-progress-step]');
    const errorText = box.querySelector('[data-progress-error]');
    const actions = box.querySelector('[data-progress-actions]');
    let percent = 0;
    const setStep = (key, value) => {
        if (steps[key]) {
            stepText.textContent = steps[key];
        }
        // Never backwards: the download's first answer is GitHub's redirect.
        if (value > percent) {
            percent = Math.min(100, value);
            bar.value = percent;
        }
    };
    const retry = box.querySelector('[data-progress-retry]');
    let retryForm = null;
    const fail = (message, form = null) => {
        errorText.textContent = message;
        errorText.hidden = false;
        actions.hidden = false;
        retryForm = form;
        if (retry) retry.hidden = form === null;
        (form && retry ? retry : actions.querySelector('[data-progress-close]')).focus();
    };
    box.querySelector('[data-progress-close]').addEventListener('click', () => { box.hidden = true; });
    // A87: the first request failed – try again from the pop-up instead of the old error page.
    retry?.addEventListener('click', () => {
        const form = retryForm;
        box.hidden = true;
        if (form) form.requestSubmit();
    });

    const follow = () => {
        const until = Date.now() + 5 * 60 * 1000;
        let answeredAt = 0;
        let over = false;
        const finish = url => {
            over = true;
            setStep('done', 100);
            window.location.href = url;
        };
        // The run takes about a minute; its answer may also never come (proxy timeout) – the file decides.
        fetch('/install.php?run=1', { credentials: 'same-origin' })
            .then(response => {
                if (response.redirected && new URL(response.url).pathname === '/update') {
                    finish(response.url);
                } else if (response.ok) {
                    answeredAt = Date.now();
                }
            })
            .catch(() => { /* keep following the file */ });
        const tick = () => {
            if (over) {
                return;
            }
            fetch(`/install-status.json?t=${Date.now()}`, { credentials: 'same-origin', cache: 'no-store' })
                .then(response => (response.ok ? response.json() : null))
                .catch(() => null)
                .then(status => {
                    if (over) {
                        return;
                    }
                    if (status && status.state === 'done') {
                        finish('/update?updated=1');
                        return;
                    }
                    if (status && status.state === 'error') {
                        over = true;
                        fail(status.message || box.dataset.failed);
                        return;
                    }
                    if (status) {
                        setStep(status.step, Number(status.percent) || 0);
                    }
                    if ((answeredAt && Date.now() - answeredAt > 5000) || Date.now() > until) {
                        over = true;
                        fail(answeredAt ? box.dataset.failed : box.dataset.timeout);
                        return;
                    }
                    setTimeout(tick, 700);
                });
        };
        tick();
    };

    const showUnreachable = (form, status) => {
        document.querySelectorAll('[data-dialog]').forEach(dialog => { dialog.hidden = true; });
        box.querySelector('[data-progress-title]').textContent = box.dataset.title.replace(':version', '');
        percent = 0;
        bar.value = 0;
        stepText.textContent = '';
        box.hidden = false;
        fail((box.dataset.unreachable || box.dataset.failed).replace(':status', status ? String(status) : '–'), form);
    };
    document.querySelectorAll('form[data-update-install]').forEach(form => form.addEventListener('submit', event => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        button.classList.add('is-busy');
        const oldWay = () => form.submit();
        fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => (response.ok ? response.json() : Promise.reject(response)))
            .then(data => {
                if (!data || typeof data.url !== 'string') {
                    oldWay();
                    return;
                }
                // An installer without a progress file: its own pages, as before.
                if (!data.progress) {
                    window.location.href = data.url;
                    return;
                }
                document.querySelectorAll('[data-dialog]').forEach(dialog => { dialog.hidden = true; });
                box.querySelector('[data-progress-title]').textContent = box.dataset.title.replace(':version', data.version || '');
                percent = 0;
                bar.value = 0;
                errorText.hidden = true;
                actions.hidden = true;
                setStep('start', 0);
                box.hidden = false;
                // The code opens the installer's session; only its "is being updated" page leads on to the run.
                fetch(data.url, { credentials: 'same-origin' })
                    .then(response => (response.ok ? response.text() : ''))
                    .catch(() => '')
                    .then(page => {
                        if (page.includes('?run=1')) {
                            follow();
                        } else {
                            window.location.href = data.url;
                        }
                    });
            })
            .catch(error => {
                // A wrong password (422) or a redirect with a message: the page shows it, as before.
                if (!(error instanceof Response) || error.status === 422) {
                    if (error instanceof Response) oldWay(); else showUnreachable(form, 0);
                    return;
                }
                showUnreachable(form, error.status);
            })
            .finally(() => {
                button.disabled = false;
                button.classList.remove('is-busy');
            });
    }));
}; if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setupUpdateProgress); else setupUpdateProgress();
})();
