/* Hochlade-Dialog (points 27–29, A77–A81; components/_upload-dialog.blade.php, core/upload.css).
   DocHamster.uploadFiles(files, { url, folder, name }) sends the files one by one (each its own request, so one bad file
   never stops the rest), shows the bar by data volume, "1,8 MB von 4,2 MB · 850 KB/s · noch etwa 3 Sekunden", "2 von 3"
   and a row per file. "Im Hintergrund weiter" shrinks the dialog to the chip at the bottom right, a click on it brings it
   back. At the end: the protocol (uploaded, not uploaded with the reason, already there – A80), "Erneut versuchen" for the
   failed ones, "Protokoll kopieren", "Erledigt"; one file without a problem closes by itself after 1.5 s. While files are
   still going, leaving the page asks first (A81). Resolves with { done, failed, known } once nothing is left to send. */
(() => {
    const DocHamster = window.DocHamster;
    if (!DocHamster) {
        return;
    }
    const AUTO_CLOSE = 1500;
    const fill = (text, values) => Object.entries(values).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value)), text ?? '');
    const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const size = bytes => {
        const units = ['B', 'KB', 'MB', 'GB'];
        let value = bytes;
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) {
            value /= 1024;
            unit++;
        }
        return `${value.toLocaleString('de-DE', { maximumFractionDigits: unit < 2 ? 0 : 1 })} ${units[unit]}`;
    };

    let run = null; // the one upload shown right now

    const parts = () => {
        const box = document.querySelector('[data-upload-dialog]');
        return box && {
            box,
            t: box.dataset,
            title: box.querySelector('[data-upload-title]'),
            target: box.querySelector('[data-upload-target]'),
            bar: box.querySelector('[data-upload-bar]'),
            stats: box.querySelector('[data-upload-stats]'),
            count: box.querySelector('[data-upload-count]'),
            list: box.querySelector('[data-upload-files]'),
            background: box.querySelector('[data-upload-background]'),
            retry: box.querySelector('[data-upload-retry]'),
            protocol: box.querySelector('[data-upload-protocol]'),
            protocolText: box.querySelector('[data-upload-protocol] textarea'),
            logged: box.querySelector('[data-upload-logged]'),
            done: box.querySelector('[data-upload-done]'),
            chip: document.querySelector('[data-upload-chip]'),
            chipText: document.querySelector('[data-upload-chip-text]'),
            chipBar: document.querySelector('[data-upload-chip-bar]'),
        };
    };

    const send = (item, options, onProgress) => new Promise(resolve => {
        const body = new FormData();
        body.append('files[]', item.file);
        if (options.folder) {
            body.append('folder', options.folder);
        }
        const xhr = new XMLHttpRequest();
        xhr.open('POST', options.url);
        xhr.setRequestHeader('X-CSRF-TOKEN', token());
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', event => onProgress(event.loaded));
        xhr.addEventListener('load', () => {
            let json = {};
            try { json = JSON.parse(xhr.responseText); } catch { /* not JSON */ }
            resolve(xhr.status >= 200 && xhr.status < 300 ? { ok: true, known: (json.known ?? []).length > 0 } : { ok: false, status: xhr.status, message: json.message });
        });
        xhr.addEventListener('error', () => resolve({ ok: false, status: 0 }));
        xhr.send(body);
    });

    const render = () => {
        const p = parts();
        if (!p || !run) {
            return;
        }
        const { t } = p;
        const items = run.items;
        const total = items.reduce((sum, item) => sum + item.file.size, 0);
        const sent = items.reduce((sum, item) => sum + item.sent, 0);
        const finished = items.filter(item => ['done', 'known', 'failed'].includes(item.state)).length;
        const failed = items.filter(item => item.state === 'failed').length;
        const known = items.filter(item => item.state === 'known').length;
        const ok = items.filter(item => item.state === 'done' || item.state === 'known').length;
        const busy = run.busy;
        const share = total > 0 ? sent / total : (finished / Math.max(1, items.length));

        p.title.textContent = busy
            ? (items.length === 1 ? fill(t.titleOne, { name: items[0].file.name }) : fill(t.titleMany, { count: items.length }))
            : (failed > 0 ? t.titleProblems : t.titleDone);
        p.target.textContent = fill(t.target, { name: run.options.name ?? '' });
        p.bar.value = Math.round(share * 1000);
        p.chipBar.value = p.bar.value;
        if (busy) {
            const speed = run.speed();
            const left = speed > 0 ? (total - sent) / speed : null;
            const leftText = left === null ? '' : (left >= 90 ? fill(t.leftMinutes, { count: Math.ceil(left / 60) }) : fill(t.leftSeconds, { count: Math.max(1, Math.ceil(left)) }));
            p.stats.textContent = [fill(t.stats, { done: size(sent), total: size(total) }), speed > 0 ? fill(t.speed, { speed: size(speed) }) : '', leftText].filter(Boolean).join(' · ');
        } else {
            p.stats.textContent = fill(t.summary, { done: ok - known, failed, known });
        }
        p.count.textContent = items.length > 1 ? fill(t.count, { done: finished, total: items.length }) : '';
        p.count.hidden = items.length < 2;
        p.chipText.textContent = items.length > 1 ? `${finished}/${items.length}` : `${Math.round(share * 100)} %`;

        p.list.hidden = items.length < 2 && !failed;
        items.forEach(item => {
            if (!item.row) {
                item.row = document.createElement('li');
                item.row.className = 'upload-file';
                item.row.innerHTML = '<span class="upload-file-name"><strong></strong><small></small></span><span class="pill"></span><progress max="1000" value="0"></progress>';
                item.row.querySelector('strong').textContent = item.file.name;
                p.list.append(item.row);
            }
            const pill = item.row.querySelector('.pill');
            pill.textContent = t[`state${item.state[0].toUpperCase()}${item.state.slice(1)}`] ?? '';
            pill.className = `pill${item.state === 'done' ? ' pill-ok' : item.state === 'failed' ? ' pill-warning' : ''}`;
            item.row.dataset.state = item.state;
            item.row.querySelector('small').textContent = item.state === 'failed' ? item.reason : size(item.file.size);
            const mine = item.row.querySelector('progress');
            mine.value = item.file.size > 0 ? Math.round((item.sent / item.file.size) * 1000) : 0;
            mine.hidden = item.state !== 'sending';
        });

        p.background.hidden = !busy;
        p.retry.hidden = busy || failed === 0;
        // A83: the protocol as text at the end – for several files or any problem.
        const showProtocol = !busy && (items.length > 1 || failed > 0);
        if (showProtocol && p.protocol.hidden) {
            p.protocolText.value = protocol();
        }
        p.protocol.hidden = !showProtocol;
        p.list.hidden = p.list.hidden || showProtocol;
        p.done.hidden = busy;
    };

    const show = visible => {
        const p = parts();
        if (!p) {
            return;
        }
        p.box.hidden = !visible;
        p.chip.hidden = visible || !run;
    };

    const protocol = () => {
        const p = parts();
        const { t } = p;
        const names = state => run.items.filter(item => item.state === state).map(item => `- ${item.file.name}${state === 'failed' ? ` – ${item.reason}` : ''}`);
        const block = (head, lines) => (lines.length ? [``, `${head}:`, ...lines] : []);
        return [
            fill(t.logHead, { name: run.options.name ?? '', date: new Date().toLocaleString('de-DE') }),
            p.stats.textContent,
            ...block(t.logDone, names('done')),
            ...block(t.logFailed, names('failed')),
            ...block(t.logKnown, names('known')),
        ].join('\n');
    };

    const leave = event => {
        if (run?.busy) {
            event.preventDefault();
            event.returnValue = parts()?.t.leave ?? '';
        }
    };

    const process = async () => {
        run.busy = true;
        addEventListener('beforeunload', leave);
        render();
        for (const item of run.items.filter(each => each.state === 'waiting')) {
            item.state = 'sending';
            item.sent = 0;
            render();
            const answer = await send(item, run.options, loaded => {
                item.sent = Math.min(loaded, item.file.size);
                run.samples.push([performance.now(), run.items.reduce((sum, each) => sum + each.sent, 0)]);
                render();
            });
            if (answer.ok) {
                item.sent = item.file.size;
                item.state = answer.known ? 'known' : 'done';
            } else {
                item.sent = 0;
                item.state = 'failed';
                const t = parts().t;
                item.reason = answer.status === 0 ? t.errorNetwork : answer.status === 413 ? t.errorSize : (answer.message || t.errorOther);
            }
            render();
            // The windows follow while it goes, at most every 3 seconds (and once more at the end).
            if (performance.now() - (run.lastAfter ?? 0) > 3000) {
                run.lastAfter = performance.now();
                run.options.after?.();
            }
        }
        run.busy = false;
        removeEventListener('beforeunload', leave);
        render();
        const failed = run.items.filter(item => item.state === 'failed').length;
        // A84: from two files on, or with a problem, the protocol also goes into the Logbuch.
        if (run.items.length > 1 || failed > 0) {
            const body = new FormData();
            body.append('name', run.options.name ?? '');
            run.items.forEach(item => {
                const key = item.state === 'failed' ? 'failed' : item.state === 'known' ? 'known' : 'done';
                body.append(`${key}[]`, item.state === 'failed' ? `${item.file.name} – ${item.reason}` : item.file.name);
            });
            const p = parts();
            const mine = run;
            fetch(p.t.protocolUrl, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token(), Accept: 'application/json' } })
                .then(response => { if (response.ok && run === mine) p.logged.hidden = false; })
                .catch(() => { /* the protocol stays in the dialog */ });
        }
        const result = { done: run.items.filter(item => item.state === 'done').length, failed, known: run.items.filter(item => item.state === 'known').length };
        run.resolve?.(result);
        if (run.items.length === 1 && failed === 0) {
            const mine = run;
            setTimeout(() => {
                if (run === mine) {
                    close();
                }
            }, AUTO_CLOSE);
        } else if (!parts().chip.hidden) {
            show(true); // the result needs a look: back from the background
        }
        return result;
    };

    const close = () => {
        if (run?.busy) {
            return;
        }
        run = null;
        const p = parts();
        if (p) {
            p.list.replaceChildren();
            p.protocol.hidden = true;
            p.logged.hidden = true;
            show(false);
            p.chip.hidden = true;
        }
    };

    DocHamster.uploadFiles = (files, options) => {
        const p = parts();
        if (!p || files.length === 0) {
            return Promise.resolve({ done: 0, failed: 0, known: 0 });
        }
        if (run?.busy) {
            // Still going: the new files join the running list.
            run.items.push(...[...files].map(file => ({ file, state: 'waiting', sent: 0, reason: '', row: null })));
            render();
            return new Promise(resolve => { const before = run.resolve; run.resolve = result => { before?.(result); resolve(result); }; });
        }
        close();
        p.list.replaceChildren();
        run = {
            options,
            busy: false,
            items: [...files].map(file => ({ file, state: 'waiting', sent: 0, reason: '', row: null })),
            samples: [],
            // Speed over the last 3 seconds of samples.
            speed() {
                const now = performance.now();
                this.samples = this.samples.filter(([at]) => now - at < 3000);
                if (this.samples.length < 2) {
                    return 0;
                }
                const [t0, b0] = this.samples[0];
                const [t1, b1] = this.samples.at(-1);
                return t1 > t0 ? ((b1 - b0) / (t1 - t0)) * 1000 : 0;
            },
        };
        show(true);
        return new Promise(resolve => {
            run.resolve = resolve;
            process();
        });
    };

    document.addEventListener('click', event => {
        const p = parts();
        if (!p || !run) {
            return;
        }
        if (event.target.closest('[data-upload-background]')) {
            show(false);
        } else if (event.target.closest('[data-upload-chip]')) {
            show(true);
        } else if (event.target.closest('[data-upload-done]')) {
            close();
        } else if (event.target.closest('[data-upload-retry]')) {
            run.items.filter(item => item.state === 'failed').forEach(item => { item.state = 'waiting'; item.reason = ''; });
            const before = run.resolve;
            run.resolve = result => before?.(result);
            process();
        }
    });
    document.addEventListener('keydown', event => {
        // Escape while running = "Im Hintergrund weiter"; after the end it closes like "Erledigt".
        const p = parts();
        if (event.key === 'Escape' && p && !p.box.hidden && run) {
            event.preventDefault();
            run.busy ? show(false) : close();
        }
    });
})();
