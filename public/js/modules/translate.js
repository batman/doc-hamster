// Point 172: "Übersetzen" in the head of "Erkannter Text". The recognised text is translated in the browser by a Web
// Worker (/js/translate-worker.js, Mozilla's Bergamot models from the installed packs – the server does no work), line
// by line so receipts keep their layout, then stored with the document (one per target language; translating again
// replaces it) and shown under the original, read-only and copyable. Everything is scoped to the clicked window.
(() => {
    let worker = null;
    let next = 0;
    const waiting = new Map();

    const run = job => {
        if (!worker) {
            worker = new Worker(job.worker);
            worker.addEventListener('message', event => {
                const { id, lines, error } = event.data ?? {};
                const done = waiting.get(id);
                waiting.delete(id);
                if (done) error ? done.reject(new Error(error)) : done.resolve(lines);
            });
            worker.addEventListener('error', () => {
                waiting.forEach(done => done.reject(new Error('worker')));
                waiting.clear();
                worker = null;
            });
        }
        const id = ++next;
        return new Promise((resolve, reject) => {
            waiting.set(id, { resolve, reject });
            worker.postMessage({ id, glue: job.glue, engine: job.engine, steps: job.steps, lines: job.lines });
        });
    };

    // Receipts are often in capitals, which the models read badly: such a line goes in as "Summe" instead of "SUMME".
    const soften = line => /\p{L}{3}/u.test(line) && line === line.toUpperCase() ? line.charAt(0) + line.slice(1).toLowerCase() : line;

    // Lines and page breaks stay where they were; only lines with letters are sent.
    const translateText = async (text, job) => {
        const parts = text.split(/(\r?\n|\f)/);
        const slots = [];
        parts.forEach((part, i) => { if (i % 2 === 0 && /\p{L}/u.test(part)) slots.push(i); });
        if (!slots.length) return text;
        const out = await run({ ...job, lines: slots.map(i => soften(parts[i].trim())) });
        slots.forEach((slot, n) => {
            const lead = parts[slot].match(/^\s*/)[0];
            parts[slot] = lead + (out[n] ?? parts[slot].trim());
        });
        return parts.join('');
    };

    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const toast = (text, error = false) => { if (text) DocHamster.toast?.(text, { error }); };

    const show = (section, to, label, text) => {
        const list = section.querySelector('[data-translations]');
        const template = list?.querySelector('template');
        if (!list || !template) return;
        list.querySelector(`[data-translation="${CSS.escape(to)}"]`)?.remove();
        const item = template.content.firstElementChild.cloneNode(true);
        item.dataset.translation = to;
        item.querySelector('[data-translation-label]').textContent = label;
        item.querySelector('[data-translation-text]').textContent = text;
        list.append(item);
    };

    document.addEventListener('click', event => {
        const copyButton = event.target.closest?.('[data-translation-copy]');
        if (copyButton) {
            const text = copyButton.closest('[data-translation]')?.querySelector('[data-translation-text]')?.textContent ?? '';
            DocHamster.copyText?.(text.replace(/\f/g, '\n\n').trim()).then(ok => toast(ok ? copyButton.dataset.done : copyButton.dataset.failed, !ok));
            return;
        }
        const button = event.target.closest?.('[data-translate-run]');
        const group = button?.closest('[data-translate]');
        const section = button?.closest('.detail-section');
        const plain = section?.querySelector('.is-plain');
        if (!group || !section || !plain || button.classList.contains('is-busy')) return;
        let config;
        try { config = JSON.parse(group.dataset.translate); } catch { return; }
        const labels = JSON.parse(group.dataset.labels || '{}');
        const to = group.querySelector('select')?.value || Object.keys(config.targets)[0];
        const target = config.targets[to];
        if (!target) return;

        button.classList.add('is-busy');
        button.disabled = true;
        toast(labels.running);
        translateText(plain.textContent, { worker: config.worker, glue: config.glue, engine: config.engine, steps: target.steps })
            .then(text => {
                const body = new FormData();
                body.append('from', config.from);
                body.append('to', to);
                body.append('text', text);
                return fetch(config.store, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' } })
                    .then(response => response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`)))
                    .then(json => {
                        show(section, to, json.label ?? target.label, text);
                        toast(json.message);
                    });
            })
            .catch(() => toast(labels.failed, true))
            .finally(() => {
                button.classList.remove('is-busy');
                button.disabled = false;
            });
    });
})();
