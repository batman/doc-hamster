(() => {
    // Farbwahl (points 14-15; PO 2026-11-16): the palette circles plus one more circle "Eigene Farbe" – a radio like the
    // others whose value is the own colour. A click on it opens our own colour dialog (never the browser's picker): a
    // colour area (saturation, brightness), a hue slider and, one below the other, HEX, R, G, B; "Übernehmen" sets the
    // colour, "Abbrechen", X or Escape keep the old choice. Set up in every window, also in windows loaded later.
    const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
    const toHex = ({ r, g, b }) => `#${[r, g, b].map(v => Math.round(v).toString(16).padStart(2, '0')).join('')}`;
    const fromHex = hex => {
        const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
        if (!m) return null;
        const n = parseInt(m[1], 16);
        return { r: n >> 16, g: (n >> 8) & 255, b: n & 255 };
    };
    const hsvToRgb = ({ h, s, v }) => {
        const f = n => {
            const k = (n + h / 60) % 6;
            return 255 * (v - v * s * clamp(Math.min(k, 4 - k), 0, 1));
        };
        return { r: f(5), g: f(3), b: f(1) };
    };
    const rgbToHsv = ({ r, g, b }) => {
        const [R, G, B] = [r / 255, g / 255, b / 255];
        const max = Math.max(R, G, B);
        const d = max - Math.min(R, G, B);
        let h = 0;
        if (d) {
            h = max === R ? ((G - B) / d) % 6 : max === G ? (B - R) / d + 2 : (R - G) / d + 4;
            h = (h * 60 + 360) % 360;
        }
        return { h, s: max ? d / max : 0, v: max };
    };
    const X = '<svg class="icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>';

    // The dialog: built for one pick, removed afterwards. onApply(hex) when "Übernehmen".
    const openDialog = (field, start, onApply) => {
        const t = field.dataset;
        let hsv = rgbToHsv(fromHex(start) ?? { r: 255, g: 118, b: 34 });
        const backdrop = document.createElement('div');
        backdrop.className = 'dialog-backdrop';
        const id = `color-own-${Date.now()}`;
        backdrop.innerHTML = `<div class="dialog color-dialog" role="dialog" aria-modal="true" aria-labelledby="${id}">
            <button type="button" class="icon-button button-small dialog-x" data-close></button>
            <h2 id="${id}"></h2>
            <div class="color-sv" tabindex="0"><span class="color-sv-dot"></span></div>
            <input type="range" class="color-hue" min="0" max="359" step="1">
            <div class="color-values">
                <label>HEX</label><input class="input-small" data-hex maxlength="7" spellcheck="false">
                <label data-name="r"></label><input class="input-small" data-rgb="r" inputmode="numeric" maxlength="3">
                <label data-name="g"></label><input class="input-small" data-rgb="g" inputmode="numeric" maxlength="3">
                <label data-name="b"></label><input class="input-small" data-rgb="b" inputmode="numeric" maxlength="3">
            </div>
            <div class="dialog-actions"><button type="button" class="button-quiet" data-close></button><button type="button" data-apply></button></div>
        </div>`;
        const q = sel => backdrop.querySelector(sel);
        q('.dialog-x').innerHTML = X;
        q('.dialog-x').setAttribute('aria-label', t.colorClose ?? '');
        q('h2').textContent = t.colorTitle ?? '';
        q('.color-hue').setAttribute('aria-label', t.colorHue ?? '');
        ['r', 'g', 'b'].forEach(c => { q(`[data-name="${c}"]`).textContent = t[`color${c.toUpperCase()}`] ?? c.toUpperCase(); });
        q('.dialog-actions [data-close]').textContent = t.colorCancel ?? '';
        q('[data-apply]').textContent = t.colorApply ?? '';
        const area = q('.color-sv');
        const dot = q('.color-sv-dot');
        const hue = q('.color-hue');
        const hexField = q('[data-hex]');
        const rgbFields = [...backdrop.querySelectorAll('[data-rgb]')];
        const paint = (skip = null) => {
            const rgb = hsvToRgb(hsv);
            area.style.setProperty('--hue', String(Math.round(hsv.h)));
            dot.style.left = `${hsv.s * 100}%`;
            dot.style.top = `${(1 - hsv.v) * 100}%`;
            dot.style.setProperty('--pick', toHex(rgb));
            hue.value = String(Math.round(hsv.h));
            if (skip !== hexField) hexField.value = toHex(rgb);
            rgbFields.forEach(f => { if (f !== skip) f.value = String(Math.round(rgb[f.dataset.rgb])); });
        };
        const pick = event => {
            const box = area.getBoundingClientRect();
            hsv = { ...hsv, s: clamp((event.clientX - box.left) / box.width, 0, 1), v: 1 - clamp((event.clientY - box.top) / box.height, 0, 1) };
            paint();
        };
        area.addEventListener('pointerdown', event => {
            area.setPointerCapture?.(event.pointerId);
            pick(event);
            const move = e => pick(e);
            const up = () => { area.removeEventListener('pointermove', move); area.removeEventListener('pointerup', up); };
            area.addEventListener('pointermove', move);
            area.addEventListener('pointerup', up);
        });
        area.addEventListener('keydown', event => {
            const step = event.shiftKey ? 0.1 : 0.02;
            const keys = { ArrowLeft: ['s', -step], ArrowRight: ['s', step], ArrowUp: ['v', step], ArrowDown: ['v', -step] };
            const k = keys[event.key];
            if (!k) return;
            event.preventDefault();
            hsv = { ...hsv, [k[0]]: clamp(hsv[k[0]] + k[1], 0, 1) };
            paint();
        });
        hue.addEventListener('input', () => { hsv = { ...hsv, h: Number(hue.value) }; paint(hue); });
        hexField.addEventListener('input', () => {
            const rgb = fromHex(hexField.value);
            if (rgb) { hsv = rgbToHsv(rgb); paint(hexField); }
        });
        rgbFields.forEach(f => f.addEventListener('input', () => {
            const rgb = Object.fromEntries(rgbFields.map(x => [x.dataset.rgb, clamp(Number(x.value) || 0, 0, 255)]));
            hsv = rgbToHsv(rgb);
            paint(f);
        }));
        const close = () => {
            document.removeEventListener('keydown', onKey, true);
            backdrop.remove();
        };
        const onKey = event => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                close();
            } else if (event.key === 'Enter' && event.target.closest?.('.color-values')) {
                event.preventDefault();
                q('[data-apply]').click();
            }
        };
        backdrop.addEventListener('click', event => {
            if (event.target === backdrop || event.target.closest('[data-close]')) close();
            if (event.target.closest('[data-apply]')) {
                onApply(toHex(hsvToRgb(hsv)));
                close();
            }
        });
        document.addEventListener('keydown', onKey, true);
        document.body.append(backdrop);
        paint();
        area.focus();
    };

    const setupColorField = root => root?.querySelectorAll?.('[data-color-field]:not([data-ready])').forEach(field => {
        field.dataset.ready = '';
        const own = field.querySelector('[data-color-own]');
        const hex = field.querySelector('[data-color-hex]');
        if (!own) return;
        const preview = own.nextElementSibling;
        const radios = [...field.querySelectorAll('input[type="radio"]')].filter(r => r !== own);
        radios.forEach(radio => radio.addEventListener('change', () => { if (hex) hex.value = ''; }));
        // Opening: the own circle is a radio – keep the old choice until "Übernehmen".
        own.addEventListener('click', event => {
            event.preventDefault();
            const before = field.querySelector('input[type="radio"]:checked');
            openDialog(field, own.value || before?.value || '', value => {
                own.value = value;
                own.checked = true;
                if (hex) hex.value = value;
                preview.classList.add('has-color');
                preview.style.setProperty('--tag', value);
                own.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });
    // Farbkreis (<x-color-swatch>): one colour in a hidden field; the circle shows it and opens the same dialog.
    const paintSwatches = root => root?.querySelectorAll?.('[data-color-swatch]').forEach(wrap => {
        const value = wrap.querySelector('input[type="hidden"]')?.value;
        if (value) wrap.querySelector('.color-swatch')?.style.setProperty('--tag', value);
    });
    document.addEventListener('click', event => {
        const swatch = event.target.closest?.('[data-color-swatch] .color-swatch');
        if (!swatch) return;
        const wrap = swatch.closest('[data-color-swatch]');
        const input = wrap.querySelector('input[type="hidden"]');
        openDialog(wrap, input?.value ?? '', value => {
            if (!input) return;
            input.value = value;
            swatch.style.setProperty('--tag', value);
            swatch.setAttribute('aria-label', `${wrap.dataset.colorTitle ?? ''}: ${value}`);
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    document.addEventListener('DOMContentLoaded', () => {
        setupColorField(document);
        paintSwatches(document);
    });
    window.DocHamster?.on?.('window:open', root => {
        setupColorField(root);
        paintSwatches(root);
    });
})();
