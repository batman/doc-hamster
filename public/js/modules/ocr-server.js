/* Server text recognition (point 186) */
// 186.2 "Server testen" on Einstellungen → Texterkennung: the server reads its built-in sample page; the recognised text
// shows next to the expected one with lines found, word count and duration. 186.3 "Mit Server neu erkennen" in the
// detail's ⋯ menu posts in the background and answers with a toast (without JavaScript the form posts as usual).
(() => {
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const post = url => fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' } })
        .then(async response => {
            const json = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(json.message || response.statusText);
            return json;
        });
    const lines = (target, text) => {
        target.replaceChildren(...String(text ?? '').split('\n').filter(line => line.trim() !== '').map(line => {
            const small = document.createElement('small');
            small.textContent = line;
            return small;
        }));
    };

    document.addEventListener('click', event => {
        const run = event.target.closest('[data-ocr-server-run]');
        const box = run?.closest('[data-ocr-server-test]');
        if (!box) return;
        const out = box.querySelector('[data-ocr-server-out]');
        const summary = box.querySelector('[data-ocr-server-summary]');
        const row = box.querySelector('[data-ocr-server-row]');
        // The row's symbol: green check when every line came out right, else the warning triangle.
        const icon = ok => row.querySelector(':scope > .icon')?.replaceWith(box.querySelector(ok ? '[data-ocr-server-ok]' : '[data-ocr-server-warn]').content.firstElementChild.cloneNode(true));
        run.disabled = true;
        run.classList.add('is-busy');
        out.hidden = false;
        summary.textContent = box.dataset.running;
        lines(box.querySelector('[data-ocr-server-expected]'), '');
        lines(box.querySelector('[data-ocr-server-text]'), '');
        post(box.dataset.ocrServerTest).then(json => {
            summary.textContent = box.dataset.result.replace(':found', json.found).replace(':total', json.total)
                .replace(':words', json.words).replace(':seconds', json.seconds);
            icon(json.ok);
            lines(box.querySelector('[data-ocr-server-expected]'), json.expected);
            lines(box.querySelector('[data-ocr-server-text]'), json.text);
        }).catch(error => {
            summary.textContent = error.message;
            icon(false);
        }).finally(() => {
            run.disabled = false;
            run.classList.remove('is-busy');
        });
    });

    document.addEventListener('submit', event => {
        const form = event.target.closest?.('form[data-ocr-server-redo]');
        if (!form) return;
        event.preventDefault();
        post(form.action).then(json => DocHamster.toast(json.message))
            .catch(error => DocHamster.toast(error.message, { error: true }));
    });
})();
