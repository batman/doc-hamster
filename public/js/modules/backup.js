(() => {
    // Einstellungen → Backup (point 206): the period fields follow "Zeitraum", the warning shows while no ZIP password
    // is typed, and the expected size is asked again on every change of the choice (never sends a password).
    // A running background backup reloads the page now and then for its progress. Delegated (app.js is in <head>).
    const timers = new WeakMap();

    const sync = form => {
        const period = form.querySelector('input[name="range"]')?.value === 'period';
        form.querySelectorAll('[data-backup-period]').forEach(el => { el.hidden = !period; });
        const warning = form.querySelector('[data-backup-warning]');
        if (warning) {
            warning.hidden = (form.querySelector('[data-backup-zip-password]')?.value ?? '') !== '';
        }
    };

    const estimate = form => {
        const params = new URLSearchParams();
        ['range', 'from', 'to', 'derived'].forEach(name => {
            const field = form.querySelector(`[name="${name}"]`);
            if (field && field.value !== '') {
                params.append(name, field.value);
            }
        });
        params.set('range', form.querySelector('input[name="range"]')?.value ?? '');
        form.querySelectorAll('input[name="parts[]"]:checked').forEach(box => params.append('parts[]', box.value));
        fetch(`${form.dataset.estimate}?${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(response => (response.ok ? response.json() : null))
            .then(body => {
                if (!body) {
                    return;
                }
                const size = form.querySelector('[data-backup-size]');
                const mode = form.querySelector('[data-backup-mode]');
                if (size) {
                    size.textContent = body.size;
                }
                if (mode) {
                    mode.textContent = body.note;
                }
            })
            .catch(() => {});
    };

    const changed = event => {
        const form = event.target instanceof Element ? event.target.closest('form[data-backup]') : null;
        if (!form) {
            return;
        }
        sync(form);
        if (event.target.matches?.('[type="password"]')) {
            return;
        }
        clearTimeout(timers.get(form));
        timers.set(form, setTimeout(() => estimate(form), 250));
    };
    document.addEventListener('change', changed);
    document.addEventListener('input', changed);

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('form[data-backup]').forEach(sync);
        if (document.querySelector('[data-backup-running]')) {
            setTimeout(() => {
                if (!document.hidden && !document.querySelector('.dialog-backdrop:not([hidden])')) {
                    window.location.reload();
                }
            }, 5000);
        }
    });
})();
