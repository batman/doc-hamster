/*
 * docHamster – the hamster as pixel art, drawn as SVG on a fixed pixel grid.
 * No dependencies, CSP-safe (no inline styles or scripts).
 *
 * The hamster is built from layers that lie on top of each other: body, head,
 * eyes, the "z" while sleeping. Animations swap whole frames on the grid,
 * nothing is scaled or moved by less than a pixel.
 *
 * Use: <div data-hamster="sleep"></div> (moods: sleep, awake, eat), optional
 * data-hamster-label="…" for a screen-reader text (without it the hamster is
 * decorative).
 * JS: var h = window.docHamster.mount(element, { mood: 'sleep' });
 *     h.mood('awake'); h.stop();
 */
(function () {
    'use strict';

    var NS = 'http://www.w3.org/2000/svg';

    // One letter per colour; '.' is transparent.
    var PALETTE = {
        k: '#1f1e1d', // outline, eyes
        w: '#ffffff', // light in the eyes
        o: '#ff7622', // fur, brand orange
        d: '#d4500f', // fur shadow
        c: '#fcedc2', // belly and face
        s: '#e1bc87', // belly shadow
        p: '#d87879', // ears, paws
        r: '#fb899b', // cheeks
        z: '#7fa7e6', // the "z" while sleeping
        a: '#f2622e', // carrot
        e: '#ff9a5c', // carrot light
        b: '#b8401a', // carrot shadow
        g: '#6cc04a', // leaves
        h: '#3f8a2c'  // leaves dark, stalks
    };

    // Lying hamster with closed eyes, 92 x 41 pixels. This is the body layer:
    // it is drawn whole, the head layer lies on top of it.
    var LYING = [
        '..................................................kkkkkkkkkkkkkk............................',
        '..............................................kkkkkdoooooooooodkkkkk........kk.k............',
        '...........................................kkkkdooooooooooooooooooodkkk....kkokok...........',
        '.........................................kkkooooooooooooooooooooooooookkk.kokooook..........',
        '.......................................kkdoooooooooooooooooooooooooooooodkkoodoookk.........',
        '......................................kkdoooooooooooooooooooooooooooooooookkoooooodk........',
        '......................................kdooooooooooooooooooooooooooooooooooookdoooddk........',
        '........kkk.......kookk............kkkkooooooooooooooooooooooooooooooooooooookdoodkk........',
        '.......kook.....kkkkookkkkkkkkk...kooookooooooooooooooooooooooooooooooooooooookddddk........',
        '......kooook...kooookookooooodkkkkoodkookooooooooooooooooooooooooooooooooooooookdddk........',
        '......kddoookkkkkoooooooooooooookoodkppokoooooooooooooooooooooooooooooooooooooookdk.........',
        '......kdddookkookkoooooooooooookdookppppkooooooooooooooooooooooooooooooooooooooook..........',
        '......kddddkoookoooooooooooooookoodkppppkooooooooooooooooooooooooooooooooooooooookk.........',
        '......kdddkooookkooooooooooooooooodkppppkoooooooooooooooooooooooooooooooooooooooook.........',
        '......kkdkooooooooooooooooooooooooddkppkdooooooooooooooooooooooooooooooooooooooooodk........',
        '.......kkooooooooooooooooooooooooooodkkkdooooooooooooooooooooooooooooooooooooooooook........',
        '........koooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooodk.......',
        '.......kooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooook.......',
        '......kkoooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooodk......',
        '......kooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooook......',
        '.....kkooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooook......',
        '.....koooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooookk.....',
        '.....kooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooodk.....',
        '....kkooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooodk.....',
        '....koooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooodk.....',
        '..kkoooooookooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooooddk.....',
        '..koookkookkoooooooookooookkooooooccccccooooooooooooooooooooooooooooooooooooooooooooddk.....',
        '.kocoookkkkooooooooookkookkoooocccccccccccooooooooooooooooooooooooooooooooooooooooooddk.....',
        '.kcccoooooooooooooooookkkkoooccccccccccccccoopkoooooooooooooooooooooooooooooooooooooddk.....',
        'kccccccooooooooooooooooooooccccccccccccccccoopkooooooooooooooccccccoooooooooooooooodddk.....',
        'kccrrrccooooooooooooooooccccrrrrccccccccccccoskooooooooooccccccccccccooooooooooooooddk......',
        'kcrrrrrccooooooooooooocccccrrrrrrcccccccccccsskooooookocccccccccccccckoooooooooooodddk......',
        'kccrrrccccooooooooooccccccccrrrrccccccccccccsskoooooookkccccccccccccckdooooooooooddddkk.....',
        'kccccccccccckspkcccccccccccccccccccccccccccssskoooooooookccccccccccccskdooooooooddddkpsk....',
        'ksccccccccccckkcccccccccccccccccccccccccccssskddoooooooooksccccccccccckdddooooddddddkpssk...',
        '.kscccccccccckcccccccccccccccccccccccccccsssskdddoooooooookkkcccccccccskdddddddddddkpppssk..',
        '.kssccccccccckcccccccccccccccccccccccccssssskdddddoooooooossskccccccsssskdddddddddkkppssssk.',
        '..ksssccckcckskccccccccccccccccccccsssssssskddddddddooooodpkkkssssssssssskkddddddkk.kppskssk',
        '...ksssssskkssskkkssssssssssssssssssssssskkddddddddddddddpssskssssssssssssskkkkkkk...kpkskkk',
        '....kkssssssssssssssssssssssssssssssssskkkddddddddddddddkppkksssssssssssssssskkk......kpksk.',
        '......kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk..........kkk..'
    ];

    // Head layer: per row of LYING, how many pixels from the left belong to the head.
    // The head lies on top of the body and does not breathe.
    var LYING_HEAD = [0, 0, 0, 0, 0, 0, 0, 39, 40, 41, 41, 41, 41, 41, 40, 40, 41, 41, 42, 43, 43,
        43, 43, 44, 45, 46, 46, 47, 47, 47, 47, 47, 47, 47, 46, 46, 45, 44, 43, 42, 42];

    // Breathing: in the "in" frame, every column from BREATH_FROM to the right is
    // lifted by one pixel above row BREATH_ROW. Rows below stay where they are.
    var BREATH_FROM = 37;
    var BREATH_ROW = 22;

    // Open eyes, laid over the head at column 5, row 22. '.' keeps what is below.
    var EYES_OPEN = {
        x: 5,
        y: 22,
        rows: [
            '..kkk...................',
            '.kwwkk...........kkk....',
            '.kwwkk..........kwwkk...',
            '.kkkkko.........kwwkk...',
            '...kw.o..........kkkkoo.',
            '.....o............kw.o..',
            '....................o...',
            '........................'
        ]
    };

    // A carrot bigger than the hamster, lying in front of him; its tip is in his mouth.
    var CARROT = {
        x: -78,
        y: 15,
        rows: [
            '...kk.........................................................................................',
            '..keekkkkkkkk.................................................................................',
            '.kaeeeeeeeeeekkkkkk...........................................................................',
            'kaaeeeeeeeeeeeeeeeekkkkkk.....................................................................',
            'aaaeeeeeeeeeeeeeeeeeeeeeekkkkkk...............................................................',
            'aaaeeeeeeeeeeeeeeeeeeeeeeeeeeeekkkkk..........................................................',
            'aaaeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeekkkkk.....................................................',
            'aaaaaaaaaeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeekkkkk................................................',
            'aaaaaaaaaaaaaaaaaaaeeeeeeeeeeeeeeeeeeeeeeeeeeekkkkk...........................................',
            'aaaaaaaaaaaaaaaaaaaaaaaaaeeeeeeeeeeeeeeeeeeeeeeeeeekkkk.......................................',
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaeeeeeeeeeeeeeeeeeeeeeeeekkkk...................................',
            'aaaaaaaaaaaaaaaaabbbbaaaaaaaaaaaaaaaaaaaaeeeeeeeeeeeeeeeeeekkkkk..............................',
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaeeeeeeeeeeeeeeeeeekkkk..........................',
            'aaaaaaaabbbbaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaeeeeeeeeeeeeeeeeekkk.......................',
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaabbbbaaaaaaaaaaaeeeeeeeeeeeekkkk...................',
            'aaaaaaaaaaaaaaaaaaaaaaaaaabbbbaaaaaaaaaaaaaaaaaaaaaaabbbbaaaaaaaeeeeeeeeeeekkkk...............',
            'bbaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaeeeeeeeekkk............',
            'bbbbbbbbbaaaaaaaaaaaaaaaaaaaaaaaaaabbbbaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaeeeeeeekkkk........',
            'bbbbbbbbbbbbbbbbbbbaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaabbbbaaaaeeeeeeekkk.....',
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbaaaaaaaaaaaaaaaaaaaaaaaaaabbbbaaaaaaaaaaaaaaaaaaaaeeekkk..',
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbaaaaaabbbaaaaaaaaaaaabbbbaaaaaeeek.',
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbk..',
            'kbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbkkkkkk...',
            '.kbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbkkkkkkkkk.........',
            '.kbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbkkkkkkkkkkk..................',
            '..kkkkkkkbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbkkkkkkkkkkkkkkkkkk.............................'
        ]
    };
    var LEAVES = {
        x: -106,
        y: 4,
        rows: [
            '...............gg..g..............',
            '..............h...g...............',
            '............gghh.gg...............',
            '...............hh.................',
            '..............ggh.gg.g............',
            '..............g.hh..g...h.g.......',
            '.............g.gghhgg..ghg........',
            '..................h...g.hh.ggg....',
            '................gghhgg..ghg.......',
            '................g..h.ggg.h..g.....',
            '........gg.....g.gghh.gg.hhg......',
            '........g...g.......hh.gg.h..ggg..',
            '........gg.g.......gghh..ghhg.....',
            '......hhh.g..g..g..gghhhgg.h.g....',
            '......hhhhh.g..gg.gg...h..ghg.....',
            '......g.hhhhhhg.g.g..gghhgghhgggg.',
            '.....ggg.g.hhhhh..g.gggghh.ghg....',
            '........g..gg.hhhhh.g.ggghggh..g..',
            '............ggg.hhhhh.g..hhhhhgg..',
            '.........g.gg...g.ghhhh.gghhghg.gg',
            '.........g.g.g.gggggghhhhgghhhhg..',
            '.........hhhhg.g.g.ggg.hhhhgh.h...',
            '.........hhhhhhhhhhhhhhg.hhhhhh...',
            '.........g.g.hhhhhhhhhhhhhhhhhhh..',
            '..........g..g.g.g.g.g.hhhhhhhhh..',
            '..........g...g..ggghhhhhhhhhhhh..',
            '..............g.hhhhhhhhhhhg......',
            '................hhhh.ggg.ggg......'
        ]
    };
    var MOUTH = 14;      // carrot pixels right of this column are inside the mouth
    var BITE_STEP = 120; // ms per tick while eating
    var BITE_TICKS = 6;  // ticks per bite; each bite pulls the carrot one pixel in
    var BITES = 30;      // then a new carrot

    // The "z" in two sizes.
    var Z = {
        m: ['zzzz', '..z.', '.z..', 'zzzz'],
        l: ['zzzzz', '...z.', '..z..', '.z...', 'zzzzz']
    };

    // Path of one "z": size and top-left pixel per step, null = not shown.
    var Z_PATH = [
        ['m', 27, 3], ['m', 28, 1], ['m', 29, -2], ['m', 30, -5], ['m', 31, -8],
        ['l', 32, -12], ['l', 33, -16], null, null
    ];
    var Z_STEP = 380; // ms per step
    var ZZZ_ROOM = 22; // free pixels above the hamster for the "z"

    function el(name, attrs) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function (key) {
            node.setAttribute(key, attrs[key]);
        });
        return node;
    }

    // Draws a raster into parent as one <path> per colour, built from horizontal
    // runs. opts: x, y (offset in pixels), to (per row: end column), clip (last column drawn).
    function draw(rows, parent, opts) {
        opts = opts || {};
        var paths = {};
        rows.forEach(function (row, y) {
            var x = 0;
            var stop = opts.to ? opts.to[y] : row.length;
            if (opts.clip !== undefined) {
                stop = Math.min(stop, opts.clip - (opts.x || 0) + 1);
            }
            while (x < stop) {
                var ch = row.charAt(x);
                var end = x;
                while (end < stop && row.charAt(end) === ch) {
                    end++;
                }
                if (ch !== '.') {
                    paths[ch] = (paths[ch] || '') + 'M' + (x + (opts.x || 0)) + ' ' + (y + (opts.y || 0)) +
                        'h' + (end - x) + 'v1h-' + (end - x) + 'z';
                }
                x = end;
            }
        });
        Object.keys(paths).forEach(function (ch) {
            parent.appendChild(el('path', { d: paths[ch], fill: PALETTE[ch] }));
        });
        return parent;
    }

    // The body breathing in: columns from BREATH_FROM lifted by one pixel above
    // BREATH_ROW. Returns rows that start one row higher (draw with y: -1).
    function breatheIn(rows) {
        var blank = new Array(rows[0].length + 1).join('.');
        var source = [blank].concat(rows);
        return source.map(function (row, y) {
            if (y > BREATH_ROW) {
                return row;
            }
            return row.slice(0, BREATH_FROM) + source[y + 1].slice(BREATH_FROM);
        });
    }

    function show(node, visible) {
        node.setAttribute('visibility', visible ? 'visible' : 'hidden');
    }

    // Plays frames in a loop: each frame is [value, ms]. Returns a stop function.
    function loop(frames, apply) {
        var i = 0;
        var timer;
        (function next() {
            var frame = frames[i];
            i = (i + 1) % frames.length;
            apply(frame[0]);
            timer = setTimeout(next, frame[1]);
        }());
        return function () {
            clearTimeout(timer);
        };
    }

    function reducedMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function mount(host, options) {
        var width = LYING[0].length;
        var height = LYING.length;
        var label = host.getAttribute('data-hamster-label');
        var svg = el('svg', {
            'shape-rendering': 'crispEdges',
            class: 'hamster'
        });
        if (label) {
            svg.setAttribute('role', 'img');
            svg.setAttribute('aria-label', label);
        } else {
            svg.setAttribute('aria-hidden', 'true');
        }

        // Layers, bottom to top.
        var bodyOut = draw(LYING, el('g', { class: 'hamster-body' }));
        var bodyIn = draw(breatheIn(LYING), el('g', { class: 'hamster-body-in' }), { y: -1 });
        var head = draw(LYING, el('g', { class: 'hamster-head' }), { to: LYING_HEAD });
        var eyes = draw(EYES_OPEN.rows, el('g', { class: 'hamster-eyes' }), { x: EYES_OPEN.x, y: EYES_OPEN.y });
        var food = el('g', { class: 'hamster-food' });
        var crumbs = el('g', { class: 'hamster-crumbs' });
        var zzz = el('g', { class: 'hamster-zzz' });
        [bodyOut, bodyIn, head, eyes, food, crumbs, zzz].forEach(function (layer) {
            svg.appendChild(layer);
        });
        host.replaceChildren(svg);

        var stops = [];
        var still = reducedMotion();

        function stopAll() {
            stops.forEach(function (stop) { stop(); });
            stops = [];
        }

        function breathe(inFrame) {
            show(bodyIn, inFrame);
            show(bodyOut, !inFrame);
        }

        // Three "z" on the same path, a third of the path apart.
        function drawZzz(tick) {
            zzz.replaceChildren();
            [0, 3, 6].forEach(function (offset) {
                var step = Z_PATH[(tick + offset) % Z_PATH.length];
                if (step) {
                    draw(Z[step[0]], zzz, { x: step[1], y: step[2] });
                }
            });
        }

        // The carrot after n bites: pulled n pixels towards the mouth.
        function drawCarrot(n) {
            food.replaceChildren();
            draw(LEAVES.rows, food, { x: LEAVES.x + n, y: LEAVES.y });
            draw(CARROT.rows, food, { x: CARROT.x + n, y: CARROT.y, clip: MOUTH });
        }

        // Crumbs fall from the mouth to the ground, one pixel per tick.
        var crumbList = [];
        function dropCrumbs(spawn) {
            crumbList = crumbList.filter(function (c) { return c.y < height - 2; });
            crumbList.forEach(function (c) { c.y++; });
            if (spawn) {
                crumbList.push({ x: MOUTH - 4 + Math.floor(Math.random() * 6), y: 34 });
            }
            crumbs.replaceChildren();
            crumbList.forEach(function (c) {
                draw(['a'], crumbs, { x: c.x, y: c.y });
            });
        }

        function awake() {
            show(eyes, true);
            if (!still) {
                stops.push(loop([[false, 1000], [true, 900]], breathe));
                stops.push(loop([[true, 3200], [false, 140], [true, 2300], [false, 140]], function (open) {
                    show(eyes, open);
                }));
            }
        }

        function setMood(mood) {
            stopAll();
            breathe(false);
            zzz.replaceChildren();
            food.replaceChildren();
            crumbs.replaceChildren();
            crumbList = [];
            svg.setAttribute('class', 'hamster hamster-' + mood);
            // The eating scene is wider (the carrot); the others leave room for the "z".
            svg.setAttribute('viewBox', mood === 'eat'
                ? LEAVES.x + ' 0 ' + (width - LEAVES.x) + ' ' + height
                : '0 ' + -ZZZ_ROOM + ' ' + width + ' ' + (height + ZZZ_ROOM));

            if (mood === 'awake') {
                awake();
                return;
            }

            if (mood === 'eat') {
                awake();
                drawCarrot(0);
                if (!still) {
                    var tick = 0;
                    stops.push(loop([[null, BITE_STEP]], function () {
                        var bite = tick % BITE_TICKS === 0;
                        if (bite) {
                            drawCarrot(Math.floor(tick / BITE_TICKS) % BITES);
                        }
                        dropCrumbs(bite);
                        tick++;
                    }));
                }
                return;
            }

            show(eyes, false);
            if (still) {
                drawZzz(1);
                return;
            }
            stops.push(loop([[false, 1400], [true, 1200]], breathe));
            var tick = 0;
            stops.push(loop([[null, Z_STEP]], function () {
                drawZzz(tick++);
            }));
        }

        setMood((options && options.mood) || 'sleep');

        return { svg: svg, mood: setMood, stop: stopAll };
    }

    window.docHamster = { mount: mount };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-hamster]').forEach(function (host) {
            mount(host, { mood: host.getAttribute('data-hamster') || 'sleep' });
        });
    });
}());
