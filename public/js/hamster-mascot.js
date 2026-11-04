/*
 * docHamster mascot – the hamster as 2D pixel art.
 *
 * Base poses (public/js/hamster-sprites.js) are traced pixel by pixel from the
 * PO's reference sheet, all on the same pixel grid, so the proportions are the
 * same in every pose; the lying pose is the sprite from public/js/hamster.js.
 * Animations are built like hamster.js: whole poses swap, parts move by whole
 * pixels (breathing, feet, nodding) and small pixel parts are laid on top
 * (eyes, document, raised arm, crumbs, the "z"). Nothing is scaled or moved by
 * less than a pixel.
 *
 * Two layers:
 *   docHamster2d.createRenderer(canvas, opts) – draws one animation on a canvas.
 *   docHamster2d.mountMascot(opts)            – the free-moving mascot: wanders,
 *       can be grabbed with the mouse (long press on touch), dragged, thrown,
 *       falls and lands, eats documents (animation only).
 *
 * No inline styles in markup, no inline scripts (CSP); the mascot positions its
 * canvas through the CSSOM only. Needs hamster-sprites.js loaded first.
 */
(function (global) {
    'use strict';

    // Fur: the DocHamster brand orange (--primary in public/css/app.css) with the
    // shadow tone hamster.js uses with it; the other colours come from the sprites.
    var PALETTE = {
        k: '#1f1e1d', o: '#ff7622', d: '#d4500f', c: '#fbedc1', s: '#e1bc87',
        p: '#d87d6a', q: '#d87d6a', r: '#fb899b', w: '#ffffff', g: '#8c97a4', z: '#7fa7e6'
    };
    var ART_W = 112, ART_H = 104, FLOOR = 2; // canvas in art pixels, rows below the feet

    // ------------------------------------------------------------ grid helpers
    // A grid is an array of equal-length strings, one letter per pixel.
    function rows(g) { return g.map(function (r) { return r.split(''); }); }
    function join(a) { return a.map(function (r) { return r.join(''); }); }
    function blankRow(w) { return new Array(w + 1).join('.').split(''); }
    // Lays `s` over `g` at x, y ('.' keeps what is below); grows g upwards or to
    // the right if needed and reports how many rows were added on top.
    function overlay(g, s, x, y) {
        var w = g[0].length, a = rows(g), top = Math.min(0, y), i, j;
        a = a.map(function (r) { while (r.length < w) { r.push('.'); } return r; });
        for (i = 0; i < -top; i++) { a.unshift(blankRow(w)); }
        y -= top;
        while (a.length < y + s.length) { a.push(blankRow(w)); }
        for (j = 0; j < s.length; j++) {
            for (i = 0; i < s[j].length; i++) {
                if (s[j][i] !== '.' && x + i >= 0 && x + i < w) { a[y + j][x + i] = s[j][i]; }
            }
        }
        return { g: join(a), top: top };
    }
    function put(g, s, x, y) { return overlay(g, s, x, y).g; }
    // Adds n transparent columns on both sides (the figure stays centred).
    function pad(g, n) { var e = new Array(n + 1).join('.'); return g.map(function (r) { return e + r + e; }); }
    // Replaces letters in a rectangle (only those listed in `from`).
    function recolor(g, x0, y0, x1, y1, from, to) {
        var a = rows(g);
        for (var y = Math.max(0, y0); y <= Math.min(a.length - 1, y1); y++) {
            for (var x = Math.max(0, x0); x <= Math.min(a[y].length - 1, x1); x++) {
                if (from.indexOf(a[y][x]) >= 0) { a[y][x] = to; }
            }
        }
        return join(a);
    }
    // n > 0: row `row` is repeated n more times (the part above moves up);
    // n < 0: -n rows from `row` are removed (squash).
    function stretch(g, row, n) {
        if (!n) { return g; }
        if (n > 0) {
            var add = [];
            for (var i = 0; i < n; i++) { add.push(g[row]); }
            return g.slice(0, row).concat(add, g.slice(row));
        }
        return g.slice(0, row).concat(g.slice(row - n));
    }
    // Moves the pixels of a rectangle by dx (vacated pixels become transparent).
    function slide(g, x0, y0, x1, y1, dx) {
        var a = rows(g), y, x;
        for (y = y0; y <= y1 && y < a.length; y++) {
            var src = a[y].slice(), w = src.length;
            for (x = x0; x <= x1 && x < w; x++) { a[y][x] = '.'; }
            for (x = x0; x <= x1 && x < w; x++) {
                var nx = x + dx;
                if (src[x] !== '.' && nx >= 0 && nx < w) { a[y][nx] = src[x]; }
            }
        }
        return join(a);
    }
    // hamster.js breathing: every column from `fromCol` is lifted by one pixel
    // above `row`. Returns rows that start one row higher.
    function breatheIn(g, fromCol, row) {
        var blank = new Array(g[0].length + 1).join('.');
        var src = [blank].concat(g);
        return src.map(function (r, y) { return y > row ? r : r.slice(0, fromCol) + src[y + 1].slice(fromCol); });
    }

    // Closes the outline after parts were moved: a coloured pixel next to
    // transparency becomes outline, a lone outline pixel goes. Only rows from
    // `y0` on (the moved feet), so the face and stamps stay untouched.
    function repairOutline(g, y0) {
        var a = rows(g), h = a.length, w = a[0].length, x, y;
        function at(yy, xx) { return yy < 0 || xx < 0 || yy >= h || xx >= w ? '.' : a[yy][xx]; }
        for (y = y0; y < h; y++) {
            for (x = 0; x < w; x++) {
                var c = a[y][x];
                if (c === '.' || c === 'k') { continue; }
                if (at(y - 1, x) === '.' || at(y + 1, x) === '.' || at(y, x - 1) === '.' || at(y, x + 1) === '.') { a[y][x] = 'k'; }
            }
        }
        for (y = y0; y < h; y++) {
            for (x = 0; x < w; x++) {
                if (a[y][x] !== 'k') { continue; }
                var n = 0;
                for (var dy = -1; dy <= 1; dy++) { for (var dx = -1; dx <= 1; dx++) { if ((dx || dy) && at(y + dy, x + dx) !== '.') { n++; } } }
                if (n === 0) { a[y][x] = '.'; }
            }
        }
        return join(a);
    }
    // An arm drawn from the shoulder (inside the body) to the paw: a 3-pixel fur
    // stroke with an outline (none near the shoulder, where it grows out of the
    // body) and a pink paw at the end.
    function arm(g, sx, sy, px, py) {
        var a = rows(g), h = a.length, w = a[0].length, painted = {}, n = Math.max(Math.abs(px - sx), Math.abs(py - sy), 1), i, x, y;
        for (i = 0; i <= n; i++) {
            var cx = Math.round(sx + (px - sx) * i / n), cy = Math.round(sy + (py - sy) * i / n);
            for (y = cy - 1; y <= cy + 1; y++) { for (x = cx - 1; x <= cx + 1; x++) { painted[y + ',' + x] = 1; } }
        }
        var ring = {};
        Object.keys(painted).forEach(function (k) {
            var p = k.split(','), yy = +p[0], xx = +p[1];
            [[1, 0], [-1, 0], [0, 1], [0, -1]].forEach(function (d) {
                var kk = (yy + d[0]) + ',' + (xx + d[1]);
                if (!painted[kk]) { ring[kk] = 1; }
            });
        });
        function set(k, c) {
            var p = k.split(','), yy = +p[0], xx = +p[1];
            if (yy >= 0 && xx >= 0 && yy < h && xx < w) { a[yy][xx] = c; }
        }
        Object.keys(ring).forEach(function (k) {
            var p = k.split(','), d = Math.abs(+p[0] - sy) + Math.abs(+p[1] - sx);
            if (d > 3) { set(k, 'k'); }
        });
        Object.keys(painted).forEach(function (k) { set(k, 'o'); });
        return put(join(a), PAW, px - 2, py - 2);
    }

    // ------------------------------------------------------------------ stamps
    var EYE = {
        open: ['.kkk.', 'kwwkk', 'kwwkk', 'kkkkk', '.kkk.'],
        right: ['.kkk.', 'kkwwk', 'kkwwk', 'kkkkk', '.kkk.'],
        blink: ['.....', '.....', 'kkkkk', '.....', '.....'],
        happy: ['.....', '.kkk.', 'k...k', '.....', '.....'],
        angryL: ['k....', '.kk..', '...kk', '.kk..', 'k....'],
        angryR: ['....k', '..kk.', 'kk...', '..kk.', '....k']
    };
    var DOC = [
        'kkkkkkkkkkkk',
        'kwwwwwwwwwwk',
        'kwggggggwwwk',
        'kwwwwwwwwwwk',
        'kwgggggggwwk',
        'kwggggggwwwk',
        'kwgggggggwwk',
        'kwwwwwwwwwwk',
        'kwggggggwwwk',
        'kwgggwwwwwwk',
        'kwwwwwwwwwwk',
        'kkkkkkkkkkkk'
    ];
    // The two halves of a torn document, with a zigzag edge.
    var DOC_L = DOC.map(function (r, y) { return r.slice(0, 6) + (y % 2 ? 'k' : '.'); });
    var DOC_R = DOC.map(function (r, y) { return (y % 2 ? '.' : 'k') + r.slice(6); });
    var PAW = ['.kk.', 'kppk', 'kppk', '.kk.'];
    var SCRAP = ['wg', 'gg'];
    var Z = { m: ['zzzz', '..z.', '.z..', 'zzzz'], l: ['zzzzz', '...z.', '..z..', '.z...', 'zzzzz'] };
    // hamster.js Z_PATH: size and top-left pixel per step (on the lying pose).
    var Z_PATH = [['m', 27, 3], ['m', 28, 1], ['m', 29, -2], ['m', 30, -5], ['m', 31, -8], ['l', 32, -12], ['l', 33, -16], null, null];

    // Standing front: shoulders and the paws' rest spots (where the traced arms are).
    // Shoulders sit just below the cheeks, so the arms stay short.
    var SH_L = [9, 33], SH_R = [32, 34], REST_L = [11, 45], REST_R = [24, 46];

    // ------------------------------------------------------------ base poses
    var B = null;
    function base() {
        if (B) { return B; }
        var S = global.docHamsterSprites;
        // Standing front without the traced happy eyes; eyes are stamped on.
        var front = recolor(recolor(S.standFront, 10, 10, 15, 14, 'kd', 'o'), 22, 10, 29, 14, 'kd', 'o');
        // Without arms: belly left/right of the belly edges, fur outside them.
        var bare = rows(front);
        for (var y = 38; y <= 53; y++) {
            var cl = 12 + (8 - 12) * (y - 36) / 14, cr = 27 + (32 - 27) * (y - 36) / 14;
            for (var x = 6; x <= 34; x++) {
                if ((x > 15 && x < 21) || (y > 50 && x < 21)) { continue; }
                bare[y][x] = x >= cl && x <= cr ? 'c' : 'o';
            }
        }
        B = {
            front: front, bare: join(bare), three: S.standThree, side: S.standSide, back: S.standBack,
            fours: S.foursSide, lieEyes: S.lieEyesOpen,
            // Sitting half-turned, with the same open eye as the standing pose.
            sit: put(recolor(S.sitThree, 10, 15, 15, 17, 'kp', 'o'), ['.kk.', 'kkkk', 'kkkk', 'kkkk'], 11, 14),
            // The hamster.js sprite has two open outline pixels at an ear tip; closed here.
            lie: put(S.lieThree, ['kk'], 19, 6)
        };
        return B;
    }
    function face(g, eye, eye2) { return put(put(g, EYE[eye], 10, 10), EYE[eye2 || eye], 23, 10); }
    function eyes(g, eye) { return eye === 'angry' ? face(g, 'angryL', 'angryR') : face(g, eye); }
    function frontEyes(eye) { return eyes(base().front, eye); }
    // Standing front with both arms drawn to the given paw spots.
    function arms(eye, l, r, grow) {
        var g = eyes(base().bare, eye);
        if (grow) { g = stretch(g, 60, grow); }
        return arm(arm(g, SH_L[0], SH_L[1], l[0], l[1]), SH_R[0], SH_R[1], r[0], r[1]);
    }
    // Paws on the left and right edge of a document lying at x, y.
    function grip(x, y, h) { var m = y + Math.floor((h || 12) / 2); return [[x, m], [x + 11, m]]; }
    function lerp(a, b, u) { return [Math.round(a[0] + (b[0] - a[0]) * u), Math.round(a[1] + (b[1] - a[1]) * u)]; }
    // Squash: rows taken out, outline closed again where the cut is.
    function squash(g, row, n) { return repairOutline(stretch(g, row, -n), 0); }
    // Breathing for standing poses: the body below the head grows by one pixel.
    function breathe(g, on) { return on ? stretch(g, 60, 1) : g; }
    // Lying pose with open eyes (hamster.js EYES_OPEN).
    function lieOpen() { var b = base(); return put(b.lie, b.lieEyes.rows, b.lieEyes.x, b.lieEyes.y); }
    // Feet of the standing front kicking: left and right foot slide apart by k.
    function kick(g, k, y0) { return repairOutline(slide(slide(g, 0, y0, 21, y0 + 4, -k), 27, y0, 48, y0 + 4, k), y0 - 1); }

    // -------------------------------------------------------------- animations
    // Each: label/sub (German UI), posture it ends in, dur (s), loop, fps and
    // frame(f, t, view) -> grid or { g, dy }. `view` is the stand pose for
    // turning (front/three/side/back). Poses face left; mirroring happens later.
    var ANIMS = {
        idle: { label: 'Idle', sub: 'Atmen · Blinzeln', posture: 'stand', dur: 3.8, loop: true, fps: 8, frame: function (f, t, view) {
            var inF = (t % 1.9) >= 1.0;
            if (view && view !== 'front') { return breathe(base()[view], inF); }
            return breathe(frontEyes((t % 3.8) >= 3.6 ? 'blink' : 'open'), inF);
        } },
        // Turning on the spot (45° per frame) before an animation shown front-on.
        turn: { label: 'Drehen', sub: 'Zur Vorderseite', posture: 'stand', dur: 0.5, loop: false, fps: 8, hidden: true, frame: function (f, t, view) {
            return view && view !== 'front' ? base()[view] : frontEyes('open');
        } },
        // Four frames: legs apart / passing (body up) / legs crossed / passing.
        walk: { label: 'Laufen', sub: 'Auf allen Vieren', posture: 'fours', dur: 0.5, loop: true, fps: 8, frame: function (f) {
            var g = base().fours, k = f % 4;
            if (k === 0) { g = repairOutline(slide(slide(g, 12, 39, 33, 42, -2), 42, 39, 63, 42, 2), 38); }
            if (k === 2) { g = repairOutline(slide(slide(g, 12, 39, 33, 42, 2), 42, 39, 63, 42, -2), 38); }
            return (k === 1 || k === 3) ? stretch(g, 30, 1) : g;
        } },
        standup: { label: 'Aufstehen', sub: 'Vierbeiner → aufrecht', posture: 'stand', dur: 0.875, loop: false, fps: 8, frame: function (f) {
            var b = base();
            return [b.fours, squash(b.fours, 30, 3), stretch(b.fours, 30, 3), b.sit, squash(b.three, 50, 4), stretch(b.three, 50, 2), b.three, b.three][Math.min(f, 7)];
        } },
        down: { label: 'Hinknien', sub: 'Aufrecht → Vierbeiner', posture: 'fours', dur: 0.625, loop: false, fps: 8, frame: function (f) {
            var b = base();
            return [b.three, squash(b.three, 50, 4), b.sit, squash(b.fours, 30, 3), stretch(b.fours, 30, 1), b.fours][Math.min(f, 5)];
        } },
        // Reach down, bring the document up, read three lines (eyes travel left
        // to right, a small nod per line), happy nod, put it away.
        read: { label: 'Dokument lesen', sub: 'Zeile für Zeile', posture: 'stand', dur: 5.5, loop: false, fps: 8, frame: function (f) {
            var hold = [13, 38], low = [13, 52];
            if (f === 0 || f >= 43) { return frontEyes('open'); }
            if (f === 1 || f === 42) { return arms('open', lerp(REST_L, grip(low[0], low[1])[0], 0.5), lerp(REST_R, grip(low[0], low[1])[1], 0.5)); }
            var pos = f === 2 || f === 41 ? low : f === 3 || f === 40 ? [13, 45] : hold;
            var p = grip(pos[0], pos[1]), eye = 'open', nod = 0;
            if (f >= 4 && f < 28) { var ph = (f - 4) % 8; eye = ph < 4 ? 'open' : 'right'; nod = ph === 0 && f > 4 ? 1 : 0; }
            if (f >= 28 && f < 40) { eye = 'happy'; nod = (f % 4) < 2 ? 1 : 0; }
            var g = arms(eye, p[0], p[1]);
            if (nod) { g = stretch(g, 24, -1); }
            return put(g, DOC, pos[0], pos[1] - (nod ? 1 : 0));
        } },
        // Bring the document to the mouth, eat it bite by bite (paws follow the
        // shrinking sheet, paper scraps fall to the floor), chew, swallow.
        eat: { label: 'Dokument fressen', sub: 'Bissen für Bissen', posture: 'stand', dur: 4.0, loop: false, fps: 8, frame: function (f) {
            if (f === 0 || f >= 31) { return frontEyes('open'); }
            if (f === 30) { return arms('happy', lerp(REST_L, [13, 30], 0.5), lerp(REST_R, [24, 30], 0.5)); }
            var lift = [[13, 44], [13, 38], [13, 31], [13, 25]], g, x = 13, y, sheet;
            if (f <= 4) {
                var at = lift[Math.min(f - 1, 3)];
                y = at[1];
                sheet = DOC;
                g = arms(f < 4 ? 'open' : 'happy', grip(x, y)[0], grip(x, y)[1]);
            } else {
                var bites = Math.min(12, Math.floor((f - 5) / 2) + 1);
                sheet = DOC.slice(bites);
                y = 22;
                var chew = (f % 2) === 0;
                if (sheet.length) {
                    var p = grip(x, y, sheet.length);
                    g = arms('happy', p[0], p[1]);
                } else {
                    g = arms('happy', [14, 31], [23, 31]);
                }
                if (chew) { g = stretch(g, 24, 1); }
                // Room on both sides for the scraps (keeps the figure centred).
                g = pad(g, 12);
                // Paper scraps: two per bite fly out of the mouth corners and fall
                // beside the body to the floor.
                for (var c = 1; c <= bites; c++) {
                    var k = f - (5 + (c - 1) * 2);
                    if (k < 1) { continue; }
                    [-1, 1].forEach(function (side) {
                        var sx = 18 + side * (6 + 4 * k), sy = 26 - 3 * k + k * k + (chew ? 1 : 0);
                        if (sy < 76) { g = put(g, SCRAP, Math.round(sx) + 12, Math.round(sy)); }
                    });
                }
            }
            var off = f > 4 ? 12 : 0;
            return sheet && sheet.length ? put(g, sheet, x + off, y + (f > 4 && (f % 2) === 0 ? 1 : 0)) : g;
        } },
        // Hold, wind up (paws together), rip, halves fly apart, throw them away
        // (arms up, scraps), halves fall, dust off the paws.
        tear: { label: 'Dokument zerreißen', sub: 'Wütend · wegwerfen', posture: 'stand', dur: 3.0, loop: false, fps: 8, frame: function (f) {
            if (f === 0 || f >= 23) { return frontEyes('open'); }
            var y = 38, P = 30;
            if (f === 1) { return put(arms('open', grip(13, 45)[0], grip(13, 45)[1]), DOC, 13, 45); }
            if (f <= 4) {
                // hold it up, then wind up: paws pull in, body tenses
                var g0 = arms('angry', grip(13, y)[0], grip(13, y)[1], f === 4 ? -1 : 0);
                return put(g0, DOC, 13, y);
            }
            var g, eye = f < 16 ? 'angry' : 'happy', lx, rx, ly, ry;
            if (f < 10) {
                // rip: the halves part, each paw on its half's outer edge
                var apart = [2, 5, 8, 9, 9][f - 5];
                lx = 13 - apart; rx = 19 + apart; ly = ry = y;
                g = pad(arms(eye, [lx, y + 6], [rx + 6, y + 6]), P);
            } else if (f === 10) {
                // throw: both halves go up with the paws
                lx = 3; rx = 39; ly = ry = 16;
                g = pad(arms(eye, [6, 22], [42, 22], 1), P);
            } else if (f < 16) {
                // let go: the halves fly out to the sides and fall to the floor
                var k = f - 10;
                lx = 3 - 5 * k; rx = 39 + 5 * k; ly = ry = Math.round(16 - 4 * k + 2.2 * k * k);
                g = pad(arms(eye, k < 2 ? [6, 22] : REST_L, k < 2 ? [42, 22] : REST_R), P);
            } else {
                // dust off: paws tap together twice
                var tap = (f % 2) === 0;
                return arms(eye, tap ? [16, 44] : REST_L, tap ? [21, 44] : REST_R);
            }
            if (ly < 78) { g = put(g, DOC_L, lx + P, ly); }
            if (ry < 78) { g = put(g, DOC_R, rx + P, ry); }
            if (f >= 6) {
                // scraps burst out of the tear and fall beside the body
                var q = f - 5;
                for (var s2 = 0; s2 < 4; s2++) {
                    var side = s2 % 2 ? 1 : -1, sp = 3 + (s2 >> 1) * 2;
                    var sx = 18 + side * sp * q, sy = 36 - (4 - (s2 >> 1)) * q + q * q;
                    if (sy < 76) { g = put(g, SCRAP, Math.round(sx) + P, Math.round(sy)); }
                }
            }
            return g;
        } },
        wave: { label: 'Winken', sub: 'Hallo!', posture: 'stand', dur: 2.0, loop: true, fps: 8, frame: function (f) {
            var g = eyes(base().bare, 'happy');
            g = arm(g, SH_L[0], SH_L[1], REST_L[0], REST_L[1]);
            return arm(g, SH_R[0], SH_R[1] - 2, f % 2 ? 41 : 44, f % 2 ? 20 : 22);
        } },
        liedown: { label: 'Hinlegen', sub: 'Vierbeiner → liegen', posture: 'lie', dur: 0.5, loop: false, fps: 8, frame: function (f) {
            var b = base();
            return [b.fours, squash(b.fours, 30, 3), squash(b.fours, 30, 6), lieOpen(), lieOpen()][Math.min(f, 4)];
        } },
        rise: { label: 'Aufrappeln', sub: 'Liegen → Vierbeiner', posture: 'fours', dur: 0.5, loop: false, fps: 8, frame: function (f) {
            var b = base();
            return [lieOpen(), squash(b.fours, 30, 6), squash(b.fours, 30, 3), stretch(b.fours, 30, 1), b.fours][Math.min(f, 4)];
        } },
        // Exactly hamster.js: only the body breathes (1.0 s out, 0.9 s in), the
        // head stays; open eyes blink 3.2 s / 0.14 s / 2.3 s / 0.14 s.
        lie: { label: 'Liegen', sub: 'Atmen · Blinzeln', posture: 'lie', dur: 5.78, loop: true, fps: 50, frame: function (f, t) {
            var b = base(), m = t % 5.78, open = !((m >= 3.2 && m < 3.34) || m >= 5.64);
            var inF = (t % 1.9) >= 1.0;
            var g = inF ? breatheIn(b.lie, 37, 22) : b.lie, dy = inF ? -1 : 0;
            if (open) { g = put(g, b.lieEyes.rows, b.lieEyes.x, b.lieEyes.y + (inF ? 1 : 0)); }
            return { g: g, dy: dy };
        } },
        sleep: { label: 'Schlafen', sub: 'Atmen · Zzz', posture: 'lie', dur: 3.42, loop: true, fps: 50, frame: function (f, t) {
            var b = base(), inF = (t % 1.9) >= 1.0;
            var g = inF ? breatheIn(b.lie, 37, 22) : b.lie, dy = inF ? -1 : 0, top = 0;
            var tick = Math.floor(t / 0.38);
            [0, 3, 6].forEach(function (off) {
                var stp = Z_PATH[(tick + off) % Z_PATH.length];
                if (!stp) { return; }
                var o = overlay(g, Z[stp[0]], stp[1], stp[2] - dy - top);
                g = o.g;
                top += o.top;
            });
            return { g: g, dy: dy };
        } },
        // Held by the scruff: stretched, arms hang out, legs kick.
        dangle: { label: 'Gehalten', sub: 'Mit der Maus gepackt', posture: 'stand', dur: 0.5, loop: true, fps: 8, frame: function (f) {
            var k = f % 2 ? 2 : -1;
            return kick(arms('open', [5, 47 + (f % 2)], [43, 47 + ((f + 1) % 2)], 3), k, 77);
        } },
        fall: { label: 'Fallen', sub: 'Arme hoch, strampeln', posture: 'stand', dur: 0.5, loop: true, fps: 8, frame: function (f) {
            var k = f % 2 ? -1 : 2;
            return kick(arms('angry', [4, 24 + (f % 2)], [44, 24 + ((f + 1) % 2)], 1), k, 75);
        } },
        land: { label: 'Landen', sub: 'Stauchen · federn', posture: 'stand', dur: 0.625, loop: false, fps: 8, frame: function (f) {
            var e = frontEyes(f < 2 ? 'angry' : 'open');
            return [squash(e, 56, 6), squash(e, 56, 3), stretch(e, 60, 2), stretch(e, 60, 1), e, e][Math.min(f, 5)];
        } }
    };
    var ORDER = ['idle', 'walk', 'standup', 'read', 'eat', 'tear', 'wave', 'lie', 'sleep', 'dangle', 'fall', 'land'];
    function frameCount(a, fps) {
        var n = Math.max(1, Math.round(a.dur * (fps || a.fps)));
        return a.loop ? n : n + 1;
    }

    var VIEWS = {
        front: { label: 'Front', heading: 0 },
        three: { label: 'Schräg', heading: -45 },
        side: { label: 'Seite', heading: -90 },
        back: { label: 'Hinten', heading: 180 },
        spin: { label: 'Drehen', heading: null }
    };
    // Heading in degrees (0 = towards the viewer, negative = facing left) to a
    // stand pose and whether it is mirrored.
    function viewOf(h) {
        h = ((h % 360) + 540) % 360 - 180;
        var a = Math.abs(h), v = a < 22.5 ? 'front' : a < 67.5 ? 'three' : a < 112.5 ? 'side' : 'back';
        return { view: v, flip: h > 0 && v !== 'front' && v !== 'back' };
    }
    // Animations that keep their own direction (left, mirrored when heading > 0).
    var DIRECTED = { walk: 1, standup: 1, down: 1, liedown: 1, rise: 1, lie: 1, sleep: 1 };

    // ================================================================ renderer
    /*
     * opts: fps (for the 8 fps animations), fur (colour),
     * onFrame(frame, total, animId), onEnd(animId),
     * strip: { canvas } – optional turnaround strip (idle from five sides).
     * The canvas is ART_W x ART_H pixels; scale it with CSS (image-rendering: pixelated).
     */
    function createRenderer(canvas, opts) {
        opts = opts || {};
        var o = { fps: opts.fps || 8 };
        var pal = {};
        function setFur(hex) {
            Object.keys(PALETTE).forEach(function (k) { pal[k] = PALETTE[k]; });
            pal.o = hex;
            if (hex.toLowerCase() !== PALETTE.o) {
                var c = parseInt(hex.replace('#', ''), 16);
                pal.d = 'rgb(' + Math.round(((c >> 16) & 255) * 0.83) + ',' + Math.round(((c >> 8) & 255) * 0.68) + ',' + Math.round((c & 255) * 0.45) + ')';
            }
        }
        setFur(opts.fur || PALETTE.o);
        canvas.width = ART_W;
        canvas.height = ART_H;
        var ctx = canvas.getContext('2d');
        var strip = opts.strip || null, sctx = null;
        if (strip) {
            strip.canvas.width = 5 * 62;
            strip.canvas.height = 88;
            sctx = strip.canvas.getContext('2d');
        }
        var bbox = { x0: 0, y0: 0, x1: 0, y1: 0 };

        function draw(c, g, flip, dy, cx, floorY) {
            var w = g[0].length, h = g.length, x0 = Math.round(cx - w / 2), y0 = floorY - h + (dy || 0);
            for (var y = 0; y < h; y++) {
                var row = g[y];
                for (var x = 0; x < w; x++) {
                    var ch = row[flip ? w - 1 - x : x];
                    if (ch === '.') { continue; }
                    c.fillStyle = pal[ch] || '#ff00ff';
                    c.fillRect(x0 + x, y0 + y, 1, 1);
                }
            }
            return { x0: x0, y0: y0, x1: x0 + w, y1: y0 + h };
        }
        function frameOf(id, f, t, heading) {
            var a = ANIMS[id], v = viewOf(heading);
            var turning = id === 'idle' || id === 'turn';
            var r = a.frame(f, t, turning ? v.view : null);
            var g = r.g ? r.g : r, dy = r.g ? r.dy : 0;
            var flip = DIRECTED[id] ? heading > 0 : (turning ? v.flip : false);
            return { g: g, dy: dy, flip: flip };
        }
        function render(id, f, t, heading) {
            var fr = frameOf(id, f, t, heading);
            ctx.clearRect(0, 0, ART_W, ART_H);
            bbox = draw(ctx, fr.g, fr.flip, fr.dy, ART_W / 2, ART_H - FLOOR);
            if (sctx) {
                sctx.clearRect(0, 0, strip.canvas.width, strip.canvas.height);
                [0, -45, -90, 180, 45].forEach(function (h, i) {
                    var sf = frameOf('idle', f, t, h);
                    draw(sctx, sf.g, sf.flip, sf.dy, 31 + i * 62, 86);
                });
            }
        }

        var st = { anim: 'idle', view: 'front', heading: 0, t: 0, clock: 0, playing: true, key: '', dirty: true, frame: -1, total: 1, ended: false, raf: 0, last: 0 };
        function fpsOf(a) { return a.fps === 8 ? o.fps : a.fps; }
        function current() {
            var a = ANIMS[st.anim], fps = fpsOf(a), total = frameCount(a, fps), f = Math.floor(st.t * fps + 1e-6);
            f = a.loop ? ((f % total) + total) % total : Math.max(0, Math.min(f, total - 1));
            return { f: f, total: total, fps: fps };
        }
        function tick(dt) {
            dt = Math.min(0.1, Math.max(0, dt || 0));
            st.clock += dt;
            if (st.playing) { st.t += dt; }
            var a = ANIMS[st.anim];
            if (!a.loop && st.t > a.dur + 0.5) { st.t = a.dur + 0.5; }
            var fr = current();
            var heading = st.view === 'spin' ? -Math.floor(st.clock / 0.5) * 45 : (st.view ? VIEWS[st.view].heading : st.heading);
            var key = st.anim + '|' + fr.f + '|' + heading;
            if (key !== st.key || st.dirty) {
                st.key = key;
                st.dirty = false;
                render(st.anim, fr.f, fr.f / fr.fps, heading);
                if (fr.f !== st.frame || fr.total !== st.total) {
                    st.frame = fr.f;
                    st.total = fr.total;
                    if (opts.onFrame) { opts.onFrame(fr.f, fr.total, st.anim); }
                }
            }
            if (!a.loop && !st.ended && st.playing && st.t >= a.dur) {
                st.ended = true;
                if (opts.onEnd) { opts.onEnd(st.anim); }
            }
        }
        function loop(now) {
            st.raf = global.requestAnimationFrame(loop);
            tick(st.last ? (now - st.last) / 1000 : 0);
            st.last = now;
        }
        return {
            tick: tick,
            run: function () { if (!st.raf) { st.raf = global.requestAnimationFrame(loop); } },
            stop: function () { global.cancelAnimationFrame(st.raf); st.raf = 0; st.last = 0; },
            setAnim: function (id) {
                if (!ANIMS[id]) { return; }
                st.anim = id; st.t = 0; st.ended = false; st.frame = -1; st.dirty = true;
            },
            anim: function () { return st.anim; },
            setView: function (id) { st.view = VIEWS[id] ? id : null; st.dirty = true; },
            setHeading: function (deg) { st.view = null; if (deg !== st.heading) { st.heading = deg; st.dirty = true; } },
            setPlaying: function (b) { st.playing = !!b; },
            step: function (d) {
                st.playing = false;
                var fr = current(), a = ANIMS[st.anim], n = fr.f + d;
                n = a.loop ? ((n % fr.total) + fr.total) % fr.total : Math.max(0, Math.min(fr.total - 1, n));
                st.t = n / fr.fps + 1e-4;
                st.dirty = true;
            },
            setOptions: function (n) {
                if (n.fps && n.fps !== o.fps) { var f = current().f; o.fps = n.fps; st.t = f / o.fps + 1e-4; }
                if (n.fur) { setFur(n.fur); }
                st.dirty = true;
            },
            // Art-pixel box of the last drawn frame (for hit tests).
            bbox: function () { return bbox; },
            size: function () { return { w: ART_W, h: ART_H, floor: FLOOR }; },
            dispose: function () { this.stop(); }
        };
    }

    // ================================================================== mascot
    var REQ = { idle: 'stand', read: 'stand', eat: 'stand', tear: 'stand', wave: 'stand', walk: 'fours', lie: 'lie', sleep: 'lie' };
    var TRANS = {
        'stand>fours': ['down'], 'fours>stand': ['standup'], 'fours>lie': ['liedown'],
        'lie>fours': ['rise'], 'stand>lie': ['down', 'liedown'], 'lie>stand': ['rise', 'standup']
    };
    var CHOICES = [['idle', 3], ['walk', 4], ['read', 0.8], ['wave', 0.8], ['lie', 1.4], ['tear', 0.4]];

    /*
     * opts: px (CSS px per art pixel, default 2), bounds (element the mascot
     * lives in; default: the viewport), canvas (use this canvas instead of
     * creating one), parent (where a created canvas goes), zIndex, autonomous
     * (default true; false under prefers-reduced-motion), x (start position),
     * label (screen-reader text; without it the mascot is decorative), fur, onEat().
     */
    function mountMascot(opts) {
        opts = opts || {};
        var px = opts.px || 2, bounds = opts.bounds || null;
        var canvas = opts.canvas, created = false;
        if (!canvas) {
            canvas = global.document.createElement('canvas');
            (opts.parent || bounds || global.document.body).appendChild(canvas);
            created = true;
        }
        var R = createRenderer(canvas, { fur: opts.fur, onFrame: onFrame, onEnd: onEnd });
        var SZ = R.size(), CW = SZ.w * px, CH = SZ.h * px, footOff = SZ.floor * px;
        var cs = canvas.style;
        cs.position = bounds ? 'absolute' : 'fixed';
        cs.left = '0';
        cs.top = '0';
        cs.width = CW + 'px';
        cs.height = CH + 'px';
        cs.pointerEvents = 'none';
        cs.imageRendering = 'pixelated';
        cs.zIndex = String(opts.zIndex || 50);
        if (opts.label) {
            canvas.setAttribute('role', 'img');
            canvas.setAttribute('aria-label', opts.label);
        } else {
            canvas.setAttribute('aria-hidden', 'true');
        }

        var reduced = !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);
        var auto = opts.autonomous !== false && !reduced;
        var GRAV = 2600, STEP = 3 * px; // walking: three art pixels per frame

        var st = {
            x: 0, y: 0, vx: 0, vy: 0, mode: 'ground', posture: 'stand',
            pending: [], action: 'idle', until: 0, now: 0, walkTo: null,
            heading: 0, headingTarget: 0, dir: -1,
            grab: null, samples: [], raf: 0, last: 0, cursor: '', eatDone: null
        };

        function area() {
            if (!bounds) { return { w: global.innerWidth, h: global.innerHeight }; }
            return { w: bounds.clientWidth, h: bounds.clientHeight };
        }
        function toLocal(cx, cy) {
            if (!bounds) { return { x: cx, y: cy }; }
            var rc = bounds.getBoundingClientRect();
            var k = bounds.clientWidth ? rc.width / bounds.clientWidth : 1;
            return { x: (cx - rc.left) / (k || 1), y: (cy - rc.top) / (k || 1) };
        }
        function floorY() { return area().h - 2; }
        function minX() { return CW * 0.45; }
        function maxX() { return Math.max(minX(), area().w - CW * 0.45); }

        st.x = typeof opts.x === 'number' ? opts.x : Math.min(maxX(), Math.max(minX(), area().w * 0.7));
        st.y = floorY();

        function place() {
            cs.transform = 'translate(' + Math.round(st.x - CW / 2) + 'px,' + Math.round(st.y - (CH - footOff)) + 'px)';
        }

        function play(id) { R.setAnim(id); }
        function startAction(name, dur) {
            var path = st.posture === REQ[name] ? [] : (TRANS[st.posture + '>' + REQ[name]] || []);
            // Animations shown front-on: turn to the front first (45° steps).
            if (REQ[name] === 'stand' && name !== 'idle' && (path.length || st.heading !== 0)) { path = path.concat(['turn']); }
            st.pending = path.concat([name]);
            st.action = null;
            st.actionDur = dur;
            next();
        }
        function next() {
            var id = st.pending.shift();
            if (!id) { decide(); return; }
            play(id);
            if (!st.pending.length) {
                st.action = id;
                st.until = st.now + (st.actionDur || 4);
            }
        }
        function decide() {
            if (st.mode !== 'ground') { return; }
            if (st.eatDone) { var cb = st.eatDone; st.eatDone = null; cb(); }
            if (!auto) { st.headingTarget = 0; startAction('idle', 1e9); return; }
            if (st.action === 'lie' && Math.random() < 0.55) { startAction('sleep', 8 + Math.random() * 10); return; }
            var total = 0, pick = 'idle';
            CHOICES.forEach(function (c) { total += c[1]; });
            var r = Math.random() * total;
            for (var i = 0; i < CHOICES.length; i++) {
                r -= CHOICES[i][1];
                if (r <= 0) { pick = CHOICES[i][0]; break; }
            }
            if (pick === 'walk') {
                var lo = minX(), hi = maxX(), tx = lo + Math.random() * (hi - lo);
                if (Math.abs(tx - st.x) < CW * 0.5) { tx = st.x > (lo + hi) / 2 ? lo : hi; }
                st.walkTo = tx;
                st.dir = tx > st.x ? 1 : -1;
                st.headingTarget = 90 * st.dir;
                startAction('walk', 1e9);
                return;
            }
            st.walkTo = null;
            st.headingTarget = pick === 'lie' ? 45 * st.dir : 0;
            var durs = { idle: 3 + Math.random() * 3, read: 5, wave: 4, lie: 5 + Math.random() * 4, tear: 99 };
            startAction(pick, durs[pick]);
        }

        // Every new animation frame: turning in 45° steps while standing, walking.
        function onFrame(f, total, id) {
            if (st.mode !== 'ground') { st.headingTarget = 0; }
            if (st.heading !== st.headingTarget) {
                var standing = id === 'idle' || id === 'turn' || st.mode !== 'ground';
                st.heading = standing ? st.heading + Math.max(-45, Math.min(45, st.headingTarget - st.heading)) : st.headingTarget;
                R.setHeading(st.heading);
                if (standing) { return; }
            }
            if (id === 'walk' && st.mode === 'ground' && st.walkTo !== null) {
                var rem = st.walkTo - st.x;
                if (Math.abs(rem) <= STEP) {
                    st.x = st.walkTo;
                    st.walkTo = null;
                    place();
                    decide();
                    return;
                }
                st.x += STEP * (rem > 0 ? 1 : -1);
                place();
            }
        }
        function onEnd(id) {
            if (st.mode !== 'ground') { return; }
            st.posture = ANIMS[id].posture;
            if (st.pending.length) { next(); } else { decide(); }
        }

        // Only the hamster itself (its pixel box), not the whole canvas, is grabbed.
        function hit(lx, ly) {
            var b = R.bbox(), x0 = st.x - CW / 2, y0 = st.y - (CH - footOff);
            return lx >= x0 + b.x0 * px && lx <= x0 + b.x1 * px && ly >= y0 + b.y0 * px && ly <= y0 + b.y1 * px;
        }
        function setCursor(c) {
            var el = bounds || global.document.documentElement;
            if (st.cursor !== c) { st.cursor = c; el.style.cursor = c; }
        }
        var press = null;
        function onDown(e) {
            if (e.button !== undefined && e.button !== 0) { return; }
            var p = toLocal(e.clientX, e.clientY);
            if (!hit(p.x, p.y)) { return; }
            e.preventDefault();
            if (e.pointerType === 'touch') {
                // Touch: a long press (350 ms) picks him up, so scrolling still works.
                press = { id: e.pointerId, x: e.clientX, y: e.clientY, timer: global.setTimeout(function () { press = null; grab(p, e.pointerId); }, 350) };
                return;
            }
            grab(p, e.pointerId);
        }
        function grab(p, pointerId) {
            st.mode = 'held';
            st.pending = [];
            st.walkTo = null;
            st.posture = 'stand';
            st.grab = { dx: st.x - p.x, dy: st.y - p.y, id: pointerId };
            st.samples = [{ t: st.now, x: st.x, y: st.y }];
            st.vx = st.vy = 0;
            st.heading = st.headingTarget = 0;
            R.setHeading(0);
            play('dangle');
            setCursor('grabbing');
        }
        function onMove(e) {
            if (press && e.pointerId === press.id && Math.hypot(e.clientX - press.x, e.clientY - press.y) > 10) {
                global.clearTimeout(press.timer);
                press = null;
            }
            var p = toLocal(e.clientX, e.clientY);
            if (st.mode !== 'held') {
                if (e.pointerType !== 'touch') { setCursor(hit(p.x, p.y) ? 'grab' : ''); }
                return;
            }
            if (e.pointerId !== st.grab.id) { return; }
            var a = area();
            st.x = Math.min(a.w - CW * 0.3, Math.max(CW * 0.3, p.x + st.grab.dx));
            st.y = Math.min(floorY(), Math.max(CH * 0.9, p.y + st.grab.dy));
            st.samples.push({ t: st.now, x: st.x, y: st.y });
            if (st.samples.length > 8) { st.samples.shift(); }
            place();
        }
        function onUp(e) {
            if (press && e.pointerId === press.id) { global.clearTimeout(press.timer); press = null; }
            if (st.mode !== 'held' || e.pointerId !== st.grab.id) { return; }
            var s0 = st.samples[0], s1 = st.samples[st.samples.length - 1], dt = Math.max(0.016, s1.t - s0.t);
            st.vx = Math.max(-2500, Math.min(2500, (s1.x - s0.x) / dt));
            st.vy = Math.max(-2500, Math.min(2500, (s1.y - s0.y) / dt));
            if (st.now - s1.t > 0.1) { st.vx = st.vy = 0; }
            st.mode = 'air';
            st.grab = null;
            play('fall');
            setCursor('');
        }

        function physics(dt) {
            var fy = floorY();
            if (st.mode === 'air') {
                st.vy += GRAV * dt;
                st.x += st.vx * dt;
                st.y += st.vy * dt;
                var lo = CW * 0.3, hi = area().w - CW * 0.3;
                if (st.x < lo) { st.x = lo; st.vx = -st.vx * 0.5; }
                if (st.x > hi) { st.x = hi; st.vx = -st.vx * 0.5; }
                if (st.y >= fy) {
                    st.y = fy;
                    if (st.vy > 1400) {
                        st.vy = -st.vy * 0.22;
                        st.vx *= 0.6;
                    } else {
                        st.mode = 'ground';
                        st.vx = st.vy = 0;
                        st.x = Math.min(maxX(), Math.max(minX(), st.x));
                        st.posture = 'stand';
                        st.pending = [];
                        st.action = 'land';
                        play('land');
                    }
                }
                place();
            } else if (st.mode === 'ground' && st.y !== fy) {
                st.y = fy;
                st.x = Math.min(maxX(), Math.max(minX(), st.x));
                place();
            }
        }

        function loop(now) {
            st.raf = global.requestAnimationFrame(loop);
            var dt = st.last ? Math.min(0.05, (now - st.last) / 1000) : 0;
            st.last = now;
            st.now += dt;
            physics(dt);
            if (st.mode === 'ground' && st.action && st.action !== 'walk' && ANIMS[st.action].loop && st.now >= st.until) {
                st.action = null;
                decide();
            }
            R.tick(dt);
        }

        var target = global.document;
        target.addEventListener('pointerdown', onDown, { passive: false });
        global.addEventListener('pointermove', onMove);
        global.addEventListener('pointerup', onUp);
        global.addEventListener('pointercancel', onUp);
        place();
        startAction('idle', 2);
        st.raf = global.requestAnimationFrame(loop);

        return {
            // Plays an animation (for `seconds` if it loops), then wanders on.
            play: function (id, seconds) {
                if (!REQ[id] || st.mode !== 'ground') { return false; }
                st.walkTo = null;
                st.headingTarget = REQ[id] === 'lie' ? 45 * st.dir : 0;
                startAction(id, seconds || 6);
                return true;
            },
            // Eats a document (animation only). Calls done() when finished.
            eat: function (done) {
                if (st.mode !== 'ground') { return false; }
                st.walkTo = null;
                st.headingTarget = 0;
                st.eatDone = function () { if (done) { done(); } if (opts.onEat) { opts.onEat(); } };
                startAction('eat', 99);
                return true;
            },
            // True if a client (viewport) point is on the hamster.
            hitTest: function (clientX, clientY) { var p = toLocal(clientX, clientY); return hit(p.x, p.y); },
            setAutonomous: function (b) { auto = !!b && !reduced; },
            setOptions: function (n) { R.setOptions(n); },
            destroy: function () {
                global.cancelAnimationFrame(st.raf);
                target.removeEventListener('pointerdown', onDown);
                global.removeEventListener('pointermove', onMove);
                global.removeEventListener('pointerup', onUp);
                global.removeEventListener('pointercancel', onUp);
                setCursor('');
                R.dispose();
                if (created && canvas.parentNode) { canvas.parentNode.removeChild(canvas); }
            }
        };
    }

    global.docHamster2d = {
        ANIMS: ANIMS, ORDER: ORDER, VIEWS: VIEWS, frameCount: frameCount,
        createRenderer: createRenderer, mountMascot: mountMascot
    };
})(window);
