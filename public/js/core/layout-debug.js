/* Layout measurement (point 63 / A90) */
// ?debug=layout switches a measurement of jumps and flicker on for this tab (sessionStorage, so it survives window
// loads); ?debug=off ends it. It logs the last user action (pointerdown, click, drag start/end), every window size
// change (id, kind, old→new width, strip/open), layout shifts (PerformanceObserver 'layout-shift', with the moved
// elements and their rect deltas) and elements that were removed/hidden and came back within 300 ms. A small panel
// bottom right shows the count, copies a plain-text log ("Messung kopieren") and empties it ("Leeren"). Only a
// developer tool: its few German strings stay in this file.
(() => {
    const KEY = 'dh-debug-layout';
    try {
        const flag = new URLSearchParams(location.search).get('debug');
        if (flag === 'layout') {
            sessionStorage.setItem(KEY, '1');
        } else if (flag === 'off') {
            sessionStorage.removeItem(KEY);
        }
        if (sessionStorage.getItem(KEY) !== '1') {
            return;
        }
    } catch {
        return;
    }
    const t0 = performance.now();
    const log = [];
    let panel = null;
    let count = null;
    let last = '–';
    const stamp = () => `${((performance.now() - t0) / 1000).toFixed(3)}s`;
    const describe = el => {
        if (!(el instanceof Element)) {
            return el?.nodeName ?? '?';
        }
        const win = el.closest('section[data-window]');
        const own = `${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}${[...el.classList].slice(0, 3).map(name => `.${name}`).join('')}`;
        const where = win ? ` ${win === el ? '=' : 'in'} ${win.dataset.windowId ?? '?'}/${win.dataset.windowKind ?? '?'}` : '';
        return own + where;
    };
    const add = (type, text) => {
        log.push(`${stamp()} ${type.padEnd(7)} ${text}${type === 'action' ? '' : ` | nach: ${last}`}`);
        if (count) {
            count.textContent = `Layout-Messung: ${log.length}`;
        }
    };
    const mine = el => el instanceof Element && panel?.contains(el);

    // User actions
    document.addEventListener('pointerdown', event => {
        if (mine(event.target)) {
            return;
        }
        last = `pointerdown ${describe(event.target)} @${Math.round(event.clientX)},${Math.round(event.clientY)}`;
        add('action', last);
    }, true);
    document.addEventListener('click', event => {
        if (mine(event.target)) {
            return;
        }
        last = `click ${describe(event.target)}`;
        add('action', last);
    }, true);
    document.addEventListener('dh:win-drag', event => {
        const win = event.detail?.win;
        last = `drag ${event.detail?.phase} ${win?.dataset.windowId ?? '?'}/${win?.dataset.windowKind ?? '?'}`;
        add('action', last);
    }, true);

    // Layout shifts
    try {
        new PerformanceObserver(list => list.getEntries().forEach(entry => {
            const parts = (entry.sources ?? []).filter(source => !mine(source.node)).map(source => {
                const a = source.previousRect;
                const b = source.currentRect;
                return `${describe(source.node)} dx=${Math.round(b.x - a.x)} dy=${Math.round(b.y - a.y)} dw=${Math.round(b.width - a.width)} dh=${Math.round(b.height - a.height)}`;
            });
            add('shift', `${entry.value.toFixed(4)}${entry.hadRecentInput ? ' (nach Eingabe)' : ''} ${parts.join('; ') || '-'}`);
        })).observe({ type: 'layout-shift', buffered: true });
    } catch { /* browser without layout-shift entries */ }

    // Window sizes and strips
    const widths = new WeakMap();
    const strips = new WeakMap();
    const sizes = new ResizeObserver(entries => entries.forEach(entry => {
        const win = entry.target;
        const now = Math.round(entry.borderBoxSize?.[0]?.inlineSize ?? entry.contentRect.width);
        const before = widths.get(win);
        widths.set(win, now);
        if (before !== undefined && before !== now) {
            add('size', `${win.dataset.windowId ?? '?'}/${win.dataset.windowKind ?? '?'} ${before}→${now}px`);
        }
    }));
    const watch = win => {
        if (!widths.has(win)) {
            sizes.observe(win);
            strips.set(win, win.classList.contains('is-min'));
        }
    };

    // Removed or hidden and back within 300 ms
    const gone = new Map();
    const visible = el => (el.checkVisibility ? el.checkVisibility() : el.getClientRects().length > 0);
    const hidden = new WeakMap();
    const back = (el, how) => {
        const at = gone.get(el);
        if (at !== undefined && performance.now() - at <= 300) {
            add('flicker', `${describe(el)} ${how} und nach ${Math.round(performance.now() - at)} ms zurück`);
        }
        gone.delete(el);
    };
    const start = () => {
        document.querySelectorAll('section[data-window]').forEach(watch);
        new MutationObserver(records => records.forEach(record => {
            if (mine(record.target)) {
                return;
            }
            if (record.type === 'childList') {
                record.removedNodes.forEach(node => {
                    if (node instanceof Element) {
                        gone.set(node, performance.now());
                        if (node.matches('section[data-window]')) {
                            add('window', `${node.dataset.windowId ?? '?'}/${node.dataset.windowKind ?? '?'} entfernt`);
                        }
                    }
                });
                record.addedNodes.forEach(node => {
                    if (node instanceof Element) {
                        back(node, 'entfernt');
                        node.matches('section[data-window]') && watch(node);
                        node.querySelectorAll?.('section[data-window]').forEach(watch);
                    }
                });
                return;
            }
            const el = record.target;
            if (!(el instanceof Element) || !el.closest('[data-windows]')) {
                return;
            }
            if (el.matches('section[data-window]') && record.attributeName === 'class') {
                const was = strips.get(el);
                const now = el.classList.contains('is-min');
                if (was !== undefined && was !== now) {
                    add('window', `${el.dataset.windowId ?? '?'}/${el.dataset.windowKind ?? '?'} ${now ? 'zu Streifen' : 'geöffnet'}`);
                }
                strips.set(el, now);
            }
            const shown = visible(el);
            if (!shown && hidden.get(el) !== false) {
                gone.set(el, performance.now());
            } else if (shown && hidden.get(el) === false) {
                back(el, 'versteckt');
            }
            hidden.set(el, shown);
        })).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style', 'hidden'] });

        // Panel (framework look: card surface, small buttons)
        panel = document.createElement('div');
        panel.className = 'layout-probe';
        count = document.createElement('strong');
        count.textContent = `Layout-Messung: ${log.length}`;
        const copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'button-quiet button-small';
        copy.textContent = 'Messung kopieren';
        copy.addEventListener('click', () => {
            const text = [`DocHamster Layout-Messung ${new Date().toISOString()} ${innerWidth}x${innerHeight} ${navigator.userAgent}`, ...log].join('\n');
            const done = label => {
                copy.textContent = label;
                setTimeout(() => { copy.textContent = 'Messung kopieren'; }, 1500);
            };
            // Without clipboard rights: the old copy command, else the console.
            const fallback = () => {
                const area = document.createElement('textarea');
                area.value = text;
                panel.append(area);
                area.select();
                const ok = document.execCommand('copy');
                area.remove();
                if (!ok) {
                    console.log(text);
                }
                done(ok ? 'Kopiert' : 'In Konsole');
            };
            (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject(new Error('no clipboard'))).then(() => done('Kopiert')).catch(fallback);
        });
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'button-quiet button-small';
        clear.textContent = 'Leeren';
        clear.addEventListener('click', () => {
            log.length = 0;
            count.textContent = 'Layout-Messung: 0';
        });
        panel.append(count, copy, clear);
        document.body.append(panel);
        // Exposed for the console and tests.
        window.DocHamster.layoutLog = log;
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
