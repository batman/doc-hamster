(() => {
    // Einstellungen → Geräte, "Verbindung testen" (point 201.5): asks GET api/upload with the shown key and says the
    // answer in plain words – OK, 401 (key does not work), 403 (person may not send), anything else, or unreachable.
    document.addEventListener('click', event => {
        const button = event.target instanceof Element ? event.target.closest('[data-device-test]') : null;
        if (!button) {
            return;
        }
        const field = document.getElementById(button.dataset.deviceTest);
        const result = button.closest('.dialog, .card, section')?.querySelector('[data-device-test-result]');
        if (!field || !result) {
            return;
        }
        const say = (text, ok) => {
            result.textContent = text;
            result.classList.toggle('check-ok', ok);
            result.classList.toggle('check-fail', !ok);
        };
        result.textContent = button.dataset.running;
        result.classList.remove('check-ok', 'check-fail');
        button.disabled = true;
        fetch(button.dataset.url, { headers: { Authorization: field.value.trim(), Accept: 'application/json' }, credentials: 'omit' })
            .then(response => response.json().catch(() => ({})).then(body => {
                if (response.ok) {
                    say(body.message ?? 'OK', true);
                } else if (response.status === 401 || response.status === 403) {
                    say(button.dataset[`msg${response.status}`], false);
                } else {
                    say(button.dataset.msgOther.replace(':status', String(response.status)), false);
                }
            }))
            .catch(() => say(button.dataset.msgFailed, false))
            .finally(() => { button.disabled = false; });
    });
})();
