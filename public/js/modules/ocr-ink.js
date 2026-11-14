/* OCR page preparation (point 184) */
// The same steps as the server (App\Domain\Ocr\PageInk, OcrWordMerge – keep both in step):
// 1. prepare(): long ruling lines (horizontal dark runs over 25 % of the width, vertical over 3 % of the height, both
//    thin) are painted white on the canvas before Tesseract reads it – text inside a thin box is missed otherwise.
// 2. uncovered(): ink areas (row bands split at wide gaps) that no recognised word overlaps; keepOnly() paints the rest
//    white for a second read with PSM 11 (sparse text).
// 3. merge(): the second read's words go into the page by position (no duplicates, no letterless words, no lines
//    unsure as a whole).
(() => {
    const DARK = 160;
    const H_LINE = 0.25;
    const V_LINE = 0.03;
    const MIN_CONFIDENCE = 50;

    // Ink mask (1 = darker than DARK) of the canvas' pixels.
    const maskOf = (data, w, h) => {
        const mask = new Uint8Array(w * h);
        for (let i = 0, p = 0; i < mask.length; i++, p += 4) {
            if (data[p + 3] >= 128 && (data[p] * 299 + data[p + 1] * 587 + data[p + 2] * 114) / 1000 < DARK) mask[i] = 1;
        }
        return mask;
    };
    const median3 = (a, b, c) => [a, b, c].sort((x, y) => x - y)[1];

    // Paints the ruling lines white in the canvas; gives { w, h, mask } for the later steps.
    const prepare = (ctx, w, h) => {
        const image = ctx.getImageData(0, 0, w, h);
        const data = image.data;
        const mask = maskOf(data, w, h);
        const thick = Math.max(3, Math.round(Math.min(w, h) * 0.004));
        const hMin = Math.max(20, Math.ceil(w * H_LINE));
        const vMin = Math.max(20, Math.ceil(h * V_LINE));
        const spanV = (x, y) => {
            let n = 1;
            for (let i = y - 1; i >= 0 && mask[i * w + x] && n < 100; i--) n++;
            for (let i = y + 1; i < h && mask[i * w + x] && n < 100; i++) n++;
            return n;
        };
        const spanH = (x, y) => {
            let n = 1;
            for (let i = x - 1; i >= 0 && mask[y * w + i] && n < 100; i--) n++;
            for (let i = x + 1; i < w && mask[y * w + i] && n < 100; i++) n++;
            return n;
        };
        const thin = (span, a, b) => median3(span(Math.round(a + (b - a) * 0.25)), span(Math.round((a + b) / 2)), span(Math.round(a + (b - a) * 0.75))) <= thick;
        const lines = [];
        for (let y = 0; y < h; y++) {
            let start = -1;
            for (let x = 0; x <= w; x++) {
                const on = x < w && mask[y * w + x];
                if (on && start < 0) start = x;
                if (!on && start >= 0) {
                    if (x - start >= hMin && thin(sx => spanV(sx, y), start, x - 1)) lines.push([true, y, start, x - 1]);
                    start = -1;
                }
            }
        }
        for (let x = 0; x < w; x++) {
            let start = -1;
            for (let y = 0; y <= h; y++) {
                const on = y < h && mask[y * w + x];
                if (on && start < 0) start = y;
                if (!on && start >= 0) {
                    if (y - start >= vMin && thin(sy => spanH(x, sy), start, y - 1)) lines.push([false, x, start, y - 1]);
                    start = -1;
                }
            }
        }
        const white = i => { data[i * 4] = data[i * 4 + 1] = data[i * 4 + 2] = 255; data[i * 4 + 3] = 255; };
        lines.forEach(([row, at, a, b]) => {
            // The light fringe next to the line goes too, never ink (text touching the line stays).
            [at - 1, at + 1].forEach(edge => {
                if (edge < 0 || edge >= (row ? h : w)) return;
                for (let i = a; i <= b; i++) {
                    const p = row ? edge * w + i : i * w + edge;
                    if (!mask[p]) white(p);
                }
            });
            for (let i = a; i <= b; i++) {
                const p = row ? at * w + i : i * w + at;
                white(p);
                mask[p] = 0;
            }
        });
        if (lines.length) ctx.putImageData(image, 0, 0);
        return { w, h, mask, lines: lines.length };
    };

    // Ink areas [x0, y0, x1, y1] in px: rows with ink grouped into bands, split at gaps over 1.5 band heights.
    const regions = ({ w, h, mask }) => {
        const out = [];
        const band = (y0, y1) => {
            const proj = new Uint8Array(w);
            for (let y = y0; y <= y1; y++) for (let x = 0; x < w; x++) if (mask[y * w + x]) proj[x] = 1;
            const gap = Math.max(Math.round((y1 - y0 + 1) * 1.5), Math.round(w * 0.01));
            let cur = null;
            let start = -1;
            for (let x = 0; x <= w; x++) {
                const on = x < w && proj[x];
                if (on && start < 0) start = x;
                if (!on && start >= 0) {
                    if (cur && start - cur[2] - 1 <= gap) cur[2] = x - 1;
                    else {
                        if (cur) out.push(cur);
                        cur = [start, y0, x - 1, y1];
                    }
                    start = -1;
                }
            }
            if (cur) out.push(cur);
        };
        let start = -1;
        let last = -1;
        for (let y = 0; y < h; y++) {
            let n = 0;
            for (let x = 0; x < w && n < 2; x++) n += mask[y * w + x];
            if (n < 2) continue;
            if (start >= 0 && y - last > 2) { band(start, last); start = -1; }
            if (start < 0) start = y;
            last = y;
        }
        if (start >= 0) band(start, last);
        return out;
    };

    // The ink areas no recognised word (relative 0..1) overlaps.
    const uncovered = (ink, words) => {
        const { w, h } = ink;
        const boxes = (words ?? []).map(o => [o.x * w, o.y * h, (o.x + o.w) * w, (o.y + o.h) * h]);
        const min = Math.max(8, Math.round(h * 0.006));
        return regions(ink).filter(r => r[3] - r[1] + 1 >= min && r[2] - r[0] + 1 >= min
            && !boxes.some(b => b[0] <= r[2] + 2 && b[2] >= r[0] - 2 && b[1] <= r[3] + 2 && b[3] >= r[1] - 2));
    };

    // Everything outside the areas (plus a margin) white, for the PSM 11 read.
    const keepOnly = (ctx, { w, h }, areas, margin = 6) => {
        const image = ctx.getImageData(0, 0, w, h);
        const data = image.data;
        const keep = new Uint8Array(w * h);
        areas.forEach(([x0, y0, x1, y1]) => {
            for (let y = Math.max(0, y0 - margin); y <= Math.min(h - 1, y1 + margin); y++) {
                keep.fill(1, y * w + Math.max(0, x0 - margin), y * w + Math.min(w - 1, x1 + margin) + 1);
            }
        });
        for (let i = 0; i < keep.length; i++) {
            if (!keep[i]) data[i * 4] = data[i * 4 + 1] = data[i * 4 + 2] = data[i * 4 + 3] = 255;
        }
        ctx.putImageData(image, 0, 0);
    };

    const overlap = (a, b) => {
        const iw = Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x);
        const ih = Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y);
        const small = Math.min(a.w * a.h, b.w * b.h);
        return iw <= 0 || ih <= 0 || small <= 0 ? 0 : iw * ih / small;
    };
    const middle = line => line.reduce((s, o) => s + o.y + o.h / 2, 0) / Math.max(1, line.length);
    const height = line => Math.max(...line.map(o => o.h));

    // The page with the second read's words added by position; gives [page, added].
    const merge = (page, extra) => {
        const old = page?.words ?? [];
        const fresh = [];
        (extra?.words ?? []).forEach(o => {
            if (!/[\p{L}\p{N}]/u.test(o.t)) return;
            if ([...old, ...fresh].some(p => overlap(o, p) > 0.3)) return;
            fresh.push(o);
        });
        const all = [];
        [...fresh].sort((a, b) => middle([a]) - middle([b])).forEach(o => {
            const last = all[all.length - 1];
            if (last && Math.abs(middle(last) - middle([o])) < Math.max(height(last), o.h) / 2) last.push(o);
            else all.push([o]);
        });
        // An unsure word inside a sure line stays (an IBAN's "DE00" often reads at 40); a line unsure as a whole is noise.
        const groups = all.filter(line => line.reduce((s, o) => s + (o.c ?? 0), 0) / line.length >= MIN_CONFIDENCE);
        if (!groups.length) return [page, 0];
        const byLine = new Map();
        old.forEach(o => { if (!byLine.has(o.l)) byLine.set(o.l, []); byLine.get(o.l).push(o); });
        const lines = [...byLine.values()];
        groups.forEach(line => {
            line.sort((a, b) => a.x - b.x);
            const mid = middle(line);
            const at = lines.findIndex(l => middle(l) > mid);
            lines.splice(at < 0 ? lines.length : at, 0, line.map(o => ({ ...o, b: -1 })));
        });
        let maxBlock = Math.max(-1, ...old.map(o => o.b));
        const words = [];
        lines.forEach((line, l) => {
            let block = null;
            line.forEach(o => {
                let b = o.b;
                if (b < 0) b = block ??= ++maxBlock;
                words.push({ ...o, l, b });
            });
        });
        return [{ n: page?.n ?? extra?.n ?? 1, words }, groups.reduce((s, line) => s + line.length, 0)];
    };

    // Text rebuilt from the words: words of a line by a space (sorted by x), lines by a line break.
    const plainText = page => {
        const byLine = new Map();
        page.words.forEach(o => { if (!byLine.has(o.l)) byLine.set(o.l, []); byLine.get(o.l).push(o); });
        return [...byLine.values()].map(line => [...line].sort((a, b) => a.x - b.x).map(o => o.t).join(' ')).join('\n');
    };

    window.DocHamster = window.DocHamster || {};
    window.DocHamster.ocrInk = { prepare, uncovered, keepOnly, merge, plainText };
})();
