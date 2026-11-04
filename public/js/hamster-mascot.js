/*
 * docHamster mascot – a real 3D figure (three.js) drawn as pixel art.
 *
 * The hamster is built from rigid parts (body, head, ears, arms, legs) that hang
 * on each other like a skeleton, so every angle and every scale stays right.
 * A two-tone toon shader plus a one-pixel outline, an orthographic camera and a
 * coarse pixel grid make it look like a hand-drawn sprite. Animations are key
 * poses sampled at a fixed frame rate (8 fps): the figure jumps from frame to
 * frame, nothing moves smoothly.
 *
 * Two layers:
 *   docHamster3d.createRenderer(THREE, canvas, opts)  – the figure on a canvas,
 *       plays the animations (used by the mascot and by viewers/tools).
 *   docHamster3d.mountMascot(THREE, opts)             – the free-moving mascot:
 *       wanders around, can be grabbed with the mouse (long press on touch),
 *       dragged, thrown, falls and lands, eats documents (animation only).
 *
 * No inline styles in markup and no inline scripts (CSP): the mascot sets its
 * canvas position through the CSSOM only. Needs three.js (r160, UMD build) as
 * window.THREE or passed in.
 */
(function (global) {
    'use strict';

    // ---------------------------------------------------------------- palette
    var C = {
        fur: '#E9862A', furShade: '#C8621B',
        cream: '#F8DFA6', creamShade: '#E5BD7A',
        pink: '#E8957F', pinkShade: '#C87563', earInner: '#E39184',
        blush: '#F6919C', nose: '#9C4A2A', dark: '#2B1408', white: '#FFFFFF',
        paper: '#FFFDF7', paperShade: '#E3DED2', ink: '#8C97A4', stamp: '#D2503C',
        outline: '#4A230E', shadow: '#A5B6C5', zz: '#7FA7E6'
    };

    function hexToRgb(hex) {
        var c = parseInt(String(hex).replace('#', ''), 16);
        return [((c >> 16) & 255) / 255, ((c >> 8) & 255) / 255, (c & 255) / 255];
    }
    function shadeOf(hex) {
        var c = hexToRgb(hex);
        return [c[0] * 0.83, c[1] * 0.68, c[2] * 0.5];
    }
    function X(a, b) {
        var o = {}, k;
        for (k in a) { o[k] = a[k]; }
        for (k in b) { o[k] = b[k]; }
        return o;
    }

    // ------------------------------------------------------------ pose channels
    var DEF = {
        y: 0, hipsY: 0.67, pitch: 0, sy: 1, flat: 1, wide: 1, bellyW: 1,
        headX: 0, headY: 0, headZ: 0, headDrop: 0, puff: 0,
        armLX: -1.1, armLZ: 0.25, armRX: -1.1, armRZ: 0.25,
        legLX: 0, legRX: 0, legBack: 0, legDown: 0, footL: 0, earL: 0, earR: 0,
        docOn: 0, docEat: 0, split: 0, fall: 0, conf: 0, zz: 0, zOn: 0, eyes: 'open'
    };
    var DISCRETE = { docOn: 1, zOn: 1, eyes: 1 };
    var LINEAR = { zz: 1, conf: 1, fall: 1 };

    var REST = { armLX: -1.1, armRX: -1.1, armLZ: 0.25, armRZ: 0.25 };
    var FOURS = { pitch: 1.2, hipsY: 0.62, headX: -1.05, armLX: -1.2, armRX: -1.2, armLZ: -0.05, armRZ: -0.05, legLX: -1.2, legRX: -1.2 };
    var LIE = {
        pitch: 1.5, hipsY: 0.4, sy: 1.38, flat: 0.92, wide: 1.12, bellyW: 1.45, headX: -1.4, headDrop: 0.17,
        armLX: -2.6, armRX: -2.6, armLZ: -0.25, armRZ: -0.25, legLX: -0.08, legRX: -1.3, legBack: -0.42, legDown: 0.22, footL: 1.72, eyes: 'open'
    };
    var HOLD = { armLX: -1.5, armRX: -1.5, armLZ: 0.33, armRZ: 0.33, headX: 0.3, docOn: 1 };
    var REACH = { armLX: -0.35, armRX: -0.35, armLZ: -0.1, armRZ: -0.1 };
    var DANGLE = { sy: 1.06, armLX: -0.5, armRX: -0.5, armLZ: -0.45, armRZ: -0.45, legLX: 0.25, legRX: -0.1, headX: -0.1, earL: 0.3, earR: 0.3 };
    var AIR = { sy: 1.05, armLX: -2.8, armRX: -2.8, armLZ: -0.6, armRZ: -0.6, legLX: -0.4, legRX: -0.4, eyes: 'angry', earL: -0.4, earR: -0.4 };

    // Each animation: duration (s), loop, label (German UI), keys [time, partial pose].
    // `posture` is where the figure ends up: stand, fours (on all fours) or lie.
    var ANIMS = {
        idle: { label: 'Idle', sub: 'Atmen · Blinzeln', posture: 'stand', dur: 2.4, loop: true, keys: [
            [0, {}],
            [0.6, { sy: 1.035, hipsY: 0.675 }],
            [1.0, { earL: 0.3 }],
            [1.15, { earL: 0 }],
            [1.2, { sy: 1, hipsY: 0.67, headZ: 0.07 }],
            [1.55, { eyes: 'blink' }],
            [1.7, { eyes: 'open' }],
            [1.8, { sy: 1.035, hipsY: 0.675 }],
            [2.4, { sy: 1, hipsY: 0.67, headZ: 0 }]
        ] },
        walk: { label: 'Laufen', sub: 'Auf allen Vieren', posture: 'fours', dur: 0.75, loop: true, keys: [
            [0, X(FOURS, { armLX: -1.55, armRX: -0.85, legLX: -0.85, legRX: -1.55, y: 0, headZ: 0, earL: 0, earR: 0 })],
            [0.1875, X(FOURS, { y: 0.04, headZ: 0.05, earL: 0.15, earR: 0.15 })],
            [0.375, X(FOURS, { armLX: -0.85, armRX: -1.55, legLX: -1.55, legRX: -0.85, y: 0, headZ: 0, earL: 0, earR: 0 })],
            [0.5625, X(FOURS, { y: 0.04, headZ: -0.05, earL: 0.15, earR: 0.15 })],
            [0.75, X(FOURS, { armLX: -1.55, armRX: -0.85, legLX: -0.85, legRX: -1.55, y: 0, headZ: 0, earL: 0, earR: 0 })]
        ] },
        standup: { label: 'Aufstehen', sub: 'Vierbeiner → aufrecht', posture: 'stand', dur: 1.75, loop: false, keys: [
            [0, X(FOURS, {})],
            [0.3, X(FOURS, { hipsY: 0.58, sy: 0.9, headX: -1.15, pitch: 1.25 })],
            [0.55, { pitch: 0.55, hipsY: 0.66, sy: 1.0, headX: -0.45, armLX: -0.9, armRX: -0.9, armLZ: 0.1, armRZ: 0.1, legLX: -0.5, legRX: -0.5 }],
            [0.8, { pitch: -0.12, hipsY: 0.72, sy: 1.1, headX: -0.15, armLX: -2.7, armRX: -2.7, armLZ: -0.35, armRZ: -0.35, legLX: 0, legRX: 0, eyes: 'happy', earL: 0.25, earR: 0.25 }],
            [1.15, { pitch: 0.04, hipsY: 0.66, sy: 0.95, headX: 0.05, armLX: -1.3, armRX: -1.3, armLZ: 0.2, armRZ: 0.2, earL: 0, earR: 0 }],
            [1.45, X(REST, { pitch: 0, hipsY: 0.67, sy: 1, headX: 0, eyes: 'open' })],
            [1.75, {}]
        ] },
        down: { label: 'Hinknien', sub: 'Aufrecht → Vierbeiner', posture: 'fours', dur: 0.5, loop: false, keys: [
            [0, {}],
            [0.25, { pitch: 0.6, hipsY: 0.66, headX: -0.5, armLX: -0.9, armRX: -0.9, legLX: -0.5, legRX: -0.5 }],
            [0.5, X(FOURS, {})]
        ] },
        read: { label: 'Dokument lesen', sub: 'Zeile für Zeile', posture: 'stand', dur: 6.0, loop: true, keys: [
            [0, {}],
            [0.25, X(REACH, { headX: 0.15 })],
            [0.5, X(HOLD, { headX: 0.25 })],
            [0.75, { headY: 0.3 }], [1.0, { headY: 0.1 }], [1.25, { headY: -0.1 }], [1.5, { headY: -0.3 }],
            [1.75, { headY: 0.3, headX: 0.33 }], [2.0, { headY: 0.1 }], [2.25, { headY: -0.1 }], [2.5, { headY: -0.3 }],
            [2.75, { headY: 0.3, headX: 0.41 }], [3.0, { headY: 0.1 }], [3.25, { headY: -0.1 }], [3.5, { headY: -0.3 }],
            [3.75, { headY: 0, headX: 0.05, eyes: 'happy' }],
            [4.0, { headX: 0.32 }], [4.25, { headX: 0.05 }], [4.5, { headX: 0.32 }],
            [4.75, { headX: 0.05, eyes: 'open' }],
            [4.9, { docOn: 0 }],
            [5.25, X(REACH, {})],
            [5.6, X(REST, { headX: 0 })],
            [6.0, {}]
        ] },
        eat: { label: 'Dokument fressen', sub: 'Bissen für Bissen', posture: 'stand', dur: 4.4, loop: false, keys: [
            [0, X(HOLD, { headX: 0.2, docEat: 0 })],
            [0.3, { headX: 0.45, eyes: 'happy' }],
            [0.45, { docEat: 0.2, puff: 0.25, headX: 0.2 }],
            [0.6, { headZ: 0.06 }], [0.75, { headZ: -0.06 }],
            [0.9, { headX: 0.45, headZ: 0 }],
            [1.05, { docEat: 0.45, puff: 0.5, headX: 0.2 }],
            [1.2, { headZ: 0.06 }], [1.35, { headZ: -0.06 }],
            [1.5, { headX: 0.45, headZ: 0 }],
            [1.65, { docEat: 0.7, puff: 0.75, headX: 0.2 }],
            [1.8, { headZ: 0.06 }], [1.95, { headZ: -0.06 }],
            [2.1, { headX: 0.45, headZ: 0 }],
            [2.25, { docEat: 0.97, puff: 1, headX: 0.15 }],
            [2.3, { docOn: 0, docEat: 0 }],
            [2.5, X(REST, { headX: 0, headZ: 0.08 })],
            [2.9, { headZ: -0.08 }],
            [3.3, { headZ: 0 }],
            [3.8, { puff: 1 }],
            [4.2, { puff: 0, eyes: 'open' }],
            [4.4, {}]
        ] },
        tear: { label: 'Dokument zerreißen', sub: 'Wütend · wegwerfen', posture: 'stand', dur: 3.6, loop: false, keys: [
            [0, X(HOLD, { headX: 0.2 })],
            [0.3, { eyes: 'angry', headX: 0.12, sy: 0.96, earL: -0.3, earR: -0.3 }],
            [0.55, { armLZ: 0.45, armRZ: 0.45, sy: 0.93 }],
            [0.8, { armLZ: 0.12, armRZ: 0.12, split: 0.35, sy: 1.02 }],
            [1.0, { armLZ: -0.05, armRZ: -0.05, armLX: -1.4, armRX: -1.4, split: 1, conf: 0, sy: 1.04 }],
            [1.4, { headX: 0.05 }],
            [1.6, { armLX: -2.3, armRX: -2.3, armLZ: -0.3, armRZ: -0.3, eyes: 'happy', fall: 0, conf: 0.4, earL: 0.15, earR: 0.15 }],
            [2.3, { fall: 1, conf: 1, armLX: -1.6, armRX: -1.6, armLZ: 0.1, armRZ: 0.1 }],
            [2.35, { docOn: 0, split: 0, fall: 0, conf: 0 }],
            [2.55, { armLX: -1.1, armRX: -1.1, armLZ: 0.5, armRZ: 0.5, earL: 0, earR: 0 }],
            [2.7, { armLZ: 0.15, armRZ: 0.15 }],
            [2.85, { armLZ: 0.5, armRZ: 0.5 }],
            [3.0, { armLZ: 0.15, armRZ: 0.15 }],
            [3.3, { armLZ: 0.25, armRZ: 0.25, headX: 0, sy: 1, eyes: 'open' }],
            [3.6, {}]
        ] },
        wave: { label: 'Winken', sub: 'Hallo!', posture: 'stand', dur: 2.0, loop: true, keys: [
            [0, { eyes: 'happy', armRX: -2.9, armRZ: -0.3, headZ: 0.1, headX: -0.05 }],
            [0.25, { armRZ: -0.85 }], [0.5, { armRZ: -0.3 }], [0.75, { armRZ: -0.85 }],
            [1.0, { armRZ: -0.3, earR: 0.25 }], [1.25, { armRZ: -0.85, earR: 0 }],
            [1.5, { armRZ: -0.3 }], [1.75, { armRZ: -0.85 }], [2.0, { armRZ: -0.3 }]
        ] },
        liedown: { label: 'Hinlegen', sub: 'Vierbeiner → liegen', posture: 'lie', dur: 0.6, loop: false, keys: [
            [0, X(FOURS, {})],
            [0.3, X(FOURS, { hipsY: 0.5, pitch: 1.35, headX: -1.2, flat: 0.85 })],
            [0.6, X(LIE, {})]
        ] },
        rise: { label: 'Aufrappeln', sub: 'Liegen → Vierbeiner', posture: 'fours', dur: 0.5, loop: false, keys: [
            [0, X(LIE, {})],
            [0.25, X(FOURS, { hipsY: 0.52, flat: 0.85 })],
            [0.5, X(FOURS, {})]
        ] },
        lie: { label: 'Liegen', sub: 'Flach ausgestreckt', posture: 'lie', dur: 4.0, loop: true, keys: [
            [0, X(LIE, {})],
            [1.2, { eyes: 'blink' }],
            [1.35, { eyes: 'open' }],
            [2.0, { flat: 0.96 }],
            [3.0, { headY: 0.15 }],
            [4.0, { flat: 0.92, headY: 0 }]
        ] },
        sleep: { label: 'Schlafen', sub: 'Flach · Zzz', posture: 'lie', dur: 3.2, loop: true, keys: [
            [0, X(LIE, { eyes: 'sleep', zOn: 1, earL: -0.3, earR: -0.3, flat: 0.9, zz: 0 })],
            [1.6, { flat: 0.96, zz: 0.5 }],
            [3.2, { flat: 0.9, zz: 1 }]
        ] },
        dangle: { label: 'Gehalten', sub: 'Mit der Maus gepackt', posture: 'stand', dur: 0.5, loop: true, keys: [
            [0, X(DANGLE, {})],
            [0.25, { legLX: -0.1, legRX: 0.25 }],
            [0.5, { legLX: 0.25, legRX: -0.1 }]
        ] },
        fall: { label: 'Fallen', sub: 'Arme hoch', posture: 'stand', dur: 0.4, loop: true, keys: [
            [0, X(AIR, {})],
            [0.2, { armLZ: -0.8, armRZ: -0.8 }],
            [0.4, { armLZ: -0.6, armRZ: -0.6 }]
        ] },
        land: { label: 'Landen', sub: 'Plattgedrückt', posture: 'stand', dur: 0.75, loop: false, keys: [
            [0, { sy: 0.72, hipsY: 0.6, eyes: 'angry', armLX: -0.6, armRX: -0.6, armLZ: -0.5, armRZ: -0.5 }],
            [0.2, { sy: 1.1, hipsY: 0.7 }],
            [0.4, { sy: 0.95, hipsY: 0.66, eyes: 'open' }],
            [0.55, X(REST, { sy: 1, hipsY: 0.67 })],
            [0.75, {}]
        ] }
    };
    var ORDER = ['idle', 'walk', 'standup', 'read', 'eat', 'tear', 'wave', 'lie', 'sleep', 'dangle', 'fall', 'land'];

    // Every key carries every channel (missing ones come from the key before).
    Object.keys(ANIMS).forEach(function (id) {
        var a = ANIMS[id], prev = X(DEF, {});
        a.rk = a.keys.map(function (k) { prev = X(prev, k[1]); return { t: k[0], p: prev }; });
    });

    function sample(a, t, keyOnly) {
        var ks = a.rk, i = 0;
        if (t <= ks[0].t) { return ks[0].p; }
        while (i < ks.length - 1 && ks[i + 1].t <= t + 1e-6) { i++; }
        var k0 = ks[i];
        if (keyOnly || i === ks.length - 1) { return k0.p; }
        var k1 = ks[i + 1], u = (t - k0.t) / (k1.t - k0.t), out = {};
        Object.keys(k0.p).forEach(function (c) {
            var v0 = k0.p[c], v1 = k1.p[c];
            if (typeof v0 !== 'number' || DISCRETE[c]) { out[c] = v0; return; }
            out[c] = v0 + (v1 - v0) * (LINEAR[c] ? u : u * u * (3 - 2 * u));
        });
        return out;
    }

    function frameCount(a, fps) {
        var n = Math.max(1, Math.round(a.dur * fps));
        return a.loop ? n : n + 1;
    }

    var VIEWS = {
        front: { label: 'Front', az: 0 },
        three: { label: 'Schräg', az: -40 },
        side: { label: 'Seite', az: -90 },
        back: { label: 'Hinten', az: 180 },
        spin: { label: 'Drehen', az: null }
    };
    var STRIP = [0, -45, -90, -135, 180];
    var HALF_H = 1.15, TARGET_Y = 0.85, ELEV = 10 * Math.PI / 180;

    // ---------------------------------------------------------------- shaders
    var TOON_VS = 'varying vec3 vN; varying float vY; void main(){ vN = normalize(normalMatrix * normal); vY = (modelMatrix * vec4(position, 1.0)).y; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }';
    var TOON_FS = 'uniform vec3 uColor; uniform vec3 uShade; uniform vec3 uLight; varying vec3 vN; varying float vY;' +
        'void main(){ if (vY < 0.0) discard; vec3 n = normalize(vN); if(!gl_FrontFacing) n = -n; float d = dot(n, normalize(uLight));' +
        ' gl_FragColor = vec4(d > 0.3 ? uColor : uShade, 1.0); }';
    var OUT_VS = 'uniform float uThick; varying float vY; void main(){ vY = (modelMatrix * vec4(position, 1.0)).y; vec4 mv = modelViewMatrix * vec4(position, 1.0);' +
        ' vec3 n = normalize(normalMatrix * normal); mv.xyz += n * uThick; gl_Position = projectionMatrix * mv; }';
    var OUT_FS = 'uniform vec3 uColor; varying float vY; void main(){ if (vY < 0.0) discard; gl_FragColor = vec4(uColor, 1.0); }';

    // ============================================================== the figure
    function buildFigure(T) {
        var LIGHT = new T.Vector3(-0.5, 0.42, 0.76).normalize();
        var mats = [], geos = [];
        function V3(rgb) { return new T.Vector3(rgb[0], rgb[1], rgb[2]); }
        function toon(color, shade, side) {
            var m = new T.ShaderMaterial({
                uniforms: { uColor: { value: V3(hexToRgb(color)) }, uShade: { value: V3(hexToRgb(shade || color)) }, uLight: { value: LIGHT } },
                vertexShader: TOON_VS, fragmentShader: TOON_FS, side: side || T.FrontSide
            });
            mats.push(m);
            return m;
        }
        var OUT = new T.ShaderMaterial({
            uniforms: { uThick: { value: 0.03 }, uColor: { value: V3(hexToRgb(C.outline)) } },
            vertexShader: OUT_VS, fragmentShader: OUT_FS, side: T.BackSide
        });
        mats.push(OUT);
        var M = {
            fur: toon(C.fur, C.furShade), cream: toon(C.cream, C.creamShade), pink: toon(C.pink, C.pinkShade),
            earIn: toon(C.earInner, C.pinkShade), blush: toon(C.blush), nose: toon(C.nose), dark: toon(C.dark),
            white: toon(C.white), paper: toon(C.paper, C.paperShade, T.DoubleSide), ink: toon(C.ink, C.ink, T.DoubleSide),
            stamp: toon(C.stamp, C.stamp, T.DoubleSide), shadow: toon(C.shadow), zz: toon(C.zz, C.zz, T.DoubleSide)
        };
        function G(g) { geos.push(g); return g; }
        var SPH = G(new T.SphereGeometry(1, 28, 18));
        function E(parent, r, p, mat, outline) {
            var m = new T.Mesh(SPH, mat);
            m.scale.set(r[0], r[1], r[2]);
            m.position.set(p[0], p[1], p[2]);
            if (outline) { m.add(new T.Mesh(SPH, OUT)); }
            parent.add(m);
            return m;
        }
        function grp(parent, p) {
            var g = new T.Group();
            if (p) { g.position.set(p[0], p[1], p[2]); }
            parent.add(g);
            return g;
        }

        var scene = new T.Scene();
        var shadow = new T.Mesh(G(new T.CircleGeometry(1, 32)), M.shadow);
        shadow.rotation.x = -Math.PI / 2;
        shadow.position.y = 0.004;
        scene.add(shadow);

        var J = {};
        J.root = grp(scene);
        J.hips = grp(J.root, [0, 0.67, 0]);
        J.squash = grp(J.hips);
        // Body: upper ellipsoid + lower "pear" blob, cream belly patch, tail.
        E(J.squash, [0.58, 0.64, 0.5], [0, 0, 0], M.fur, true);
        E(J.squash, [0.62, 0.45, 0.52], [0, -0.2, 0], M.fur, true);
        J.belly = E(J.squash, [0.42, 0.5, 0.3], [0, -0.08, 0.26], M.cream, false);
        E(J.squash, [0.09, 0.08, 0.09], [0, -0.45, -0.5], M.fur, true);

        // Head with the face from the reference sheet: big round eyes with a
        // light dot, wide cream lower face, small "w" mouth, pink cheeks.
        J.head = grp(J.hips, [0, 0.42, 0.02]);
        E(J.head, [0.5, 0.44, 0.44], [0, 0.2, 0.04], M.fur, true);
        J.pouches = [-1, 1].map(function (s) { return E(J.head, [0.2, 0.17, 0.18], [s * 0.3, 0.08, 0.2], M.fur, true); });
        E(J.head, [0.3, 0.17, 0.16], [0, 0.06, 0.37], M.cream, false);
        E(J.head, [0.035, 0.026, 0.022], [0, 0.135, 0.52], M.nose, false);
        [-1, 1].forEach(function (s) {
            var ch = E(J.head, [0.085, 0.05, 0.025], [s * 0.29, 0.14, 0.385], M.blush, false);
            ch.rotation.y = s * 0.6;
        });
        var arcSmall = G(new T.TorusGeometry(0.032, 0.014, 5, 10, Math.PI));
        [-1, 1].forEach(function (s) {
            var m = new T.Mesh(arcSmall, M.dark);
            m.position.set(s * 0.03, 0.09, 0.527);
            m.rotation.z = Math.PI;
            J.head.add(m);
        });
        var cone = G(new T.ConeGeometry(0.05, 0.14, 6));
        [[-0.07, -0.45], [0, 0], [0.07, 0.45]].forEach(function (c) {
            var m = new T.Mesh(cone, M.fur);
            m.position.set(c[0], 0.65, 0.1);
            m.rotation.set(0.35, 0, -c[1]);
            m.add(new T.Mesh(cone, OUT));
            J.head.add(m);
        });
        ['earL', 'earR'].forEach(function (id, i) {
            var s = i === 0 ? 1 : -1;
            var g = grp(J.head, [s * 0.29, 0.53, -0.02]);
            E(g, [0.13, 0.14, 0.06], [0, 0.08, 0], M.fur, true);
            E(g, [0.08, 0.09, 0.03], [0, 0.08, 0.04], M.earIn, false);
            J[id] = g;
        });
        var arc = G(new T.TorusGeometry(0.058, 0.018, 6, 14, Math.PI));
        var bar = G(new T.BoxGeometry(0.11, 0.022, 0.012));
        var barS = G(new T.BoxGeometry(0.075, 0.02, 0.012));
        J.eyes = [-1, 1].map(function (s) {
            var g = grp(J.head, [s * 0.27, 0.26, 0.4]);
            g.rotation.y = s * 0.8;
            g.translateZ(0.02);
            var open = grp(g);
            E(open, [0.062, 0.074, 0.045], [0, 0, 0], M.dark, false);
            E(open, [0.022, 0.024, 0.01], [0.018, 0.026, 0.042], M.white, false);
            var happy = new T.Mesh(arc, M.dark); happy.position.y = -0.02; g.add(happy);
            var sleep = new T.Mesh(arc, M.dark); sleep.rotation.z = Math.PI; sleep.position.y = 0.02; g.add(sleep);
            var blink = new T.Mesh(bar, M.dark); g.add(blink);
            var angry = grp(g);
            var a1 = new T.Mesh(barS, M.dark); a1.position.set(-s * 0.012, 0.018, 0); a1.rotation.z = s * 0.5; angry.add(a1);
            var a2 = new T.Mesh(barS, M.dark); a2.position.set(-s * 0.012, -0.018, 0); a2.rotation.z = -s * 0.5; angry.add(a2);
            return { open: open, happy: happy, sleep: sleep, blink: blink, angry: angry };
        });

        ['armL', 'armR'].forEach(function (id, i) {
            var s = i === 0 ? 1 : -1;
            var g = grp(J.hips, [s * 0.3, 0.12, 0.22]);
            E(g, [0.1, 0.22, 0.1], [0, -0.18, 0], M.fur, true);
            E(g, [0.075, 0.06, 0.07], [0, -0.4, 0.01], M.pink, true);
            J[id] = g;
        });
        ['legL', 'legR'].forEach(function (id, i) {
            var s = i === 0 ? 1 : -1;
            var g = grp(J.hips, [s * 0.28, -0.22, 0.1]);
            E(g, [0.2, 0.24, 0.2], [0, -0.12, 0], M.fur, true);
            var foot = grp(g, [0, -0.38, 0]);
            E(foot, [0.1, 0.05, 0.16], [0, -0.02, 0.1], M.pink, true);
            [-0.045, 0, 0.045].forEach(function (tx) { E(foot, [0.032, 0.03, 0.032], [tx, -0.03, 0.25], M.pink, true); });
            J[id] = g;
            J[id === 'legL' ? 'footL' : 'footR'] = foot;
        });

        // Document: two halves with an interlocking torn edge.
        J.doc = grp(J.hips, [0, 0.1, 0.6]);
        J.doc.rotation.x = -0.2;
        var DW = 0.17, DH = 0.22;
        var zig = [[0, -DH], [0.014, -0.16], [-0.012, -0.1], [0.016, -0.04], [-0.01, 0.02], [0.014, 0.08], [-0.014, 0.14], [0.01, 0.19], [0, DH]];
        var lineMat = new T.LineBasicMaterial({ color: C.outline });
        mats.push(lineMat);
        J.halves = [-1, 1].map(function (s) {
            var cx = s * DW / 2;
            var pts = [[s * DW, -DH]].concat(zig).concat([[s * DW, DH]]);
            var shape = new T.Shape();
            pts.forEach(function (p, i) {
                if (i === 0) { shape.moveTo(p[0] - cx, p[1]); } else { shape.lineTo(p[0] - cx, p[1]); }
            });
            var g = grp(J.doc, [cx, 0, 0]);
            g.add(new T.Mesh(G(new T.ShapeGeometry(shape)), M.paper));
            var lg = G(new T.BufferGeometry().setFromPoints(pts.map(function (p) { return new T.Vector3(p[0] - cx, p[1], 0.001); })));
            g.add(new T.LineLoop(lg, lineMat));
            var rows = [[0.165, 0.024, 0.11], [0.11, 0.014, 0.13], [0.07, 0.014, 0.12], [0.03, 0.014, 0.13], [-0.01, 0.014, 0.1], [-0.05, 0.014, 0.13], [-0.09, 0.014, 0.12], [-0.13, 0.014, s > 0 ? 0.05 : 0.13]];
            rows.forEach(function (r) {
                var len = r[2], geo = G(new T.PlaneGeometry(len, r[1]));
                var x0 = s < 0 ? -0.15 + len / 2 : 0.025 + len / 2;
                [1, -1].forEach(function (f) {
                    var m = new T.Mesh(geo, M.ink);
                    m.position.set(x0 - cx, r[0], f * 0.003);
                    g.add(m);
                });
            });
            if (s > 0) {
                var st = new T.Mesh(G(new T.RingGeometry(0.022, 0.034, 18)), M.stamp);
                st.position.set(0.1 - cx, -0.16, 0.004);
                g.add(st);
            }
            g.userData = { s: s, cx: cx };
            return g;
        });
        var seed = 7;
        function rnd() { seed = (seed * 16807) % 2147483647; return seed / 2147483647; }
        var scrapGeo = G(new T.PlaneGeometry(0.04, 0.035));
        J.conf = [];
        for (var ci = 0; ci < 12; ci++) {
            var sm = new T.Mesh(scrapGeo, M.paper);
            sm.userData = { p0: [(rnd() - 0.5) * 0.06, 0.1 + (rnd() - 0.5) * 0.35, 0.6], v: [(rnd() - 0.5) * 1.4, 0.4 + rnd() * 0.8, 0.1 + rnd() * 0.4], r: [rnd() * 9, rnd() * 9] };
            J.hips.add(sm);
            J.conf.push(sm);
        }
        J.zs = [];
        var zTop = G(new T.BoxGeometry(0.12, 0.026, 0.01)), zDiag = G(new T.BoxGeometry(0.15, 0.026, 0.01));
        for (var zi = 0; zi < 3; zi++) {
            var zg = grp(J.root);
            var t1 = new T.Mesh(zTop, M.zz); t1.position.y = 0.06; zg.add(t1);
            var t2 = new T.Mesh(zTop, M.zz); t2.position.y = -0.06; zg.add(t2);
            var t3 = new T.Mesh(zDiag, M.zz); t3.rotation.z = Math.PI / 4; zg.add(t3);
            J.zs.push(zg);
        }

        function applyPose(p) {
            J.root.position.y = p.y;
            J.hips.position.y = p.hipsY;
            J.hips.rotation.x = p.pitch;
            var k = 1 / Math.sqrt(p.sy);
            J.squash.scale.set(k * p.wide, p.sy, k * p.flat);
            J.belly.scale.x = 0.42 * p.bellyW;
            J.head.position.set(0, 0.42 * p.sy, 0.02 + p.headDrop);
            J.head.rotation.set(p.headX, p.headY, p.headZ);
            var pf = 0.55 + 0.6 * p.puff;
            J.pouches.forEach(function (m) { m.scale.set(0.2 * pf, 0.17 * pf, 0.18 * pf); });
            J.armL.position.y = J.armR.position.y = 0.12 * p.sy;
            J.armL.rotation.set(p.armLX, 0, -p.armLZ);
            J.armR.rotation.set(p.armRX, 0, p.armRZ);
            J.legL.position.y = J.legR.position.y = -0.22 + p.legBack;
            J.legL.position.z = J.legR.position.z = 0.1 + p.legDown;
            J.footL.rotation.x = p.footL;
            J.legL.rotation.x = p.legLX;
            J.legR.rotation.x = p.legRX;
            J.earL.rotation.set(p.earL, 0, -0.35);
            J.earR.rotation.set(p.earR, 0, 0.35);
            J.eyes.forEach(function (e) {
                e.open.visible = p.eyes === 'open';
                e.happy.visible = p.eyes === 'happy';
                e.sleep.visible = p.eyes === 'sleep';
                e.blink.visible = p.eyes === 'blink';
                e.angry.visible = p.eyes === 'angry';
            });
            J.doc.visible = !!p.docOn;
            var keep = Math.max(0.001, 1 - p.docEat);
            J.doc.scale.set(1, keep, 1);
            J.doc.position.y = 0.1 - DH * p.docEat;
            J.halves.forEach(function (h) {
                var s = h.userData.s;
                h.position.set(h.userData.cx + s * (0.13 * p.split + 0.12 * p.fall), -0.95 * p.fall * p.fall, 0.15 * p.fall);
                h.rotation.set(1.6 * p.fall, 0, -s * 0.5 * p.split - s * 0.8 * p.fall);
            });
            J.conf.forEach(function (m) {
                var c = p.conf, d = m.userData;
                m.visible = c > 0.001 && c < 0.999;
                m.position.set(d.p0[0] + d.v[0] * c, d.p0[1] + d.v[1] * c - 1.7 * c * c, d.p0[2] + d.v[2] * c);
                m.rotation.set(d.r[0] * c, d.r[1] * c, 0);
            });
            J.zs.forEach(function (z, i) {
                var ph = (p.zz + i / 3) % 1;
                z.visible = !!p.zOn;
                z.position.set(0.28 + 0.08 * Math.sin(ph * 6.28), 1.0 + ph * 0.6, 0.45);
                var sc = 0.6 + ph * 0.7;
                z.scale.set(sc, sc, sc);
            });
            var lie = Math.min(1, Math.abs(p.pitch) / 1.3);
            shadow.scale.set(0.62 * p.wide, 0.5 + 0.38 * lie, 1);
            shadow.position.z = 0.12 * lie;
        }

        function setFur(hex) {
            M.fur.uniforms.uColor.value.fromArray(hexToRgb(hex));
            M.fur.uniforms.uShade.value.fromArray(shadeOf(hex));
        }

        return {
            scene: scene, J: J, OUT: OUT, applyPose: applyPose, setFur: setFur,
            billboard: function (cam) { J.zs.forEach(function (z) { z.quaternion.copy(cam.quaternion); }); },
            dispose: function () {
                geos.forEach(function (g) { g.dispose(); });
                mats.forEach(function (m) { m.dispose(); });
            }
        };
    }

    // =============================================================== renderer
    /*
     * opts: width, height (CSS px of the canvas), px (size of one art pixel in
     * CSS px), outlinePx (outline in art pixels), fps, keyOnly, fur,
     * strip: { canvas, width, height } – optional turnaround strip,
     * onFrame(frame, total, animId), onEnd(animId).
     * Call tick(dt) yourself, or run() for an own animation loop.
     */
    function createRenderer(T, canvas, opts) {
        opts = opts || {};
        var W = opts.width || 192, H = opts.height || 192;
        var o = { fps: opts.fps || 8, px: opts.px || 2, outlinePx: opts.outlinePx || 1.15, keyOnly: !!opts.keyOnly };
        var fig = buildFigure(T);
        if (opts.fur) { fig.setFur(opts.fur); }

        function makeCam(aspect) { return new T.OrthographicCamera(-HALF_H * aspect, HALF_H * aspect, HALF_H, -HALF_H, 0.1, 40); }
        function aimCam(cam, azDeg) {
            var az = azDeg * Math.PI / 180, D = 12;
            cam.position.set(Math.sin(az) * Math.cos(ELEV) * D, TARGET_Y + Math.sin(ELEV) * D, Math.cos(az) * Math.cos(ELEV) * D);
            cam.lookAt(0, TARGET_Y, 0);
            cam.updateMatrixWorld();
        }
        var cam = makeCam(W / H);
        var r = new T.WebGLRenderer({ canvas: canvas, antialias: false, alpha: true });
        r.setPixelRatio(1);
        r.setClearColor(0x000000, 0);

        var strip = opts.strip || null, rs = null, camS = null;
        if (strip) {
            rs = new T.WebGLRenderer({ canvas: strip.canvas, antialias: false, alpha: true });
            rs.setPixelRatio(1);
            rs.setClearColor(0x000000, 0);
            camS = makeCam((strip.width / STRIP.length) / strip.height);
        }
        function spx() { return Math.max(2, Math.round(o.px * 0.6)); }
        function size() {
            r.setSize(Math.round(W / o.px), Math.round(H / o.px), false);
            if (rs) { rs.setSize(Math.round(strip.width / spx()), Math.round(strip.height / spx()), false); }
        }
        size();

        function render(az) {
            var hMain = Math.round(H / o.px);
            fig.OUT.uniforms.uThick.value = (2 * HALF_H / hMain) * o.outlinePx;
            aimCam(cam, az);
            fig.billboard(cam);
            r.render(fig.scene, cam);
            if (rs) {
                var w = Math.round(strip.width / spx()), h = Math.round(strip.height / spx()), cw = w / STRIP.length;
                fig.OUT.uniforms.uThick.value = (2 * HALF_H / h) * o.outlinePx;
                rs.setScissorTest(true);
                rs.setScissor(0, 0, w, h);
                rs.setViewport(0, 0, w, h);
                rs.clear();
                STRIP.forEach(function (a, i) {
                    var x = Math.round(i * cw), ww = Math.round((i + 1) * cw) - x;
                    rs.setViewport(x, 0, ww, h);
                    rs.setScissor(x, 0, ww, h);
                    aimCam(camS, a);
                    fig.billboard(camS);
                    rs.render(fig.scene, camS);
                });
            }
        }

        var st = { anim: 'idle', view: null, az: 0, t: 0, clock: 0, playing: true, key: '', dirty: true, frame: -1, total: 1, ended: false, raf: 0, last: 0 };
        function current() {
            var a = ANIMS[st.anim], total = frameCount(a, o.fps), f = Math.floor(st.t * o.fps + 1e-6);
            f = a.loop ? ((f % total) + total) % total : Math.max(0, Math.min(f, total - 1));
            return { f: f, total: total };
        }
        function tick(dt) {
            dt = Math.min(0.1, Math.max(0, dt || 0));
            st.clock += dt;
            if (st.playing) { st.t += dt; }
            var a = ANIMS[st.anim];
            if (!a.loop && st.t > a.dur + 0.5) { st.t = a.dur + 0.5; }
            var fr = current();
            var az = st.view === 'spin' ? -Math.floor(st.clock / 0.5) * 45 : (st.view ? VIEWS[st.view].az : st.az);
            var key = st.anim + '|' + fr.f + '|' + az;
            if (key !== st.key || st.dirty) {
                st.key = key;
                st.dirty = false;
                fig.applyPose(sample(a, fr.f / o.fps, o.keyOnly));
                render(az);
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
            setAzimuth: function (deg) { st.view = null; if (deg !== st.az) { st.az = deg; st.dirty = true; } },
            setPlaying: function (b) { st.playing = !!b; },
            step: function (d) {
                st.playing = false;
                var fr = current(), a = ANIMS[st.anim], n = fr.f + d;
                n = a.loop ? ((n % fr.total) + fr.total) % fr.total : Math.max(0, Math.min(fr.total - 1, n));
                st.t = n / o.fps + 1e-4;
                st.dirty = true;
            },
            setOptions: function (n) {
                if (n.fps && n.fps !== o.fps) { var f = current().f; o.fps = n.fps; st.t = f / o.fps + 1e-4; }
                if (n.px && n.px !== o.px) { o.px = n.px; size(); }
                if (typeof n.outlinePx === 'number') { o.outlinePx = n.outlinePx; }
                if (typeof n.keyOnly === 'boolean') { o.keyOnly = n.keyOnly; }
                if (n.fur) { fig.setFur(n.fur); }
                st.dirty = true;
            },
            // Distance in CSS px from the canvas bottom to the ground point.
            footOffset: function () { return H * (HALF_H - TARGET_Y * Math.cos(ELEV)) / (2 * HALF_H); },
            // CSS px per world unit (the hamster is about 1.75 units tall).
            scale: function () { return H / (2 * HALF_H); },
            dispose: function () {
                this.stop();
                fig.dispose();
                r.dispose();
                if (rs) { rs.dispose(); }
            }
        };
    }

    // ================================================================= mascot
    var REQ = { idle: 'stand', read: 'stand', eat: 'stand', tear: 'stand', wave: 'stand', walk: 'fours', lie: 'lie', sleep: 'lie' };
    var TRANS = {
        'stand>fours': ['down'], 'fours>stand': ['standup'], 'fours>lie': ['liedown'],
        'lie>fours': ['rise'], 'stand>lie': ['down', 'liedown'], 'lie>stand': ['rise', 'standup']
    };
    var CHOICES = [['idle', 3], ['walk', 4], ['read', 0.8], ['wave', 0.8], ['lie', 1.4], ['tear', 0.4]];

    /*
     * opts: size (CSS px of the hamster box, default 192), px (art pixel,
     * default 2), bounds (element the mascot lives in; default: the viewport),
     * canvas (use this canvas instead of creating one), parent (where a created
     * canvas goes), zIndex, autonomous (default true; false under
     * prefers-reduced-motion), x (start position), label (screen-reader text;
     * without it the mascot is decorative), onEat().
     */
    function mountMascot(T, opts) {
        opts = opts || {};
        T = T || global.THREE;
        var S = opts.size || 192, bounds = opts.bounds || null;
        var canvas = opts.canvas, created = false;
        if (!canvas) {
            canvas = global.document.createElement('canvas');
            (opts.parent || bounds || global.document.body).appendChild(canvas);
            created = true;
        }
        var cs = canvas.style;
        cs.position = bounds ? 'absolute' : 'fixed';
        cs.left = '0';
        cs.top = '0';
        cs.width = S + 'px';
        cs.height = S + 'px';
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
        var R = createRenderer(T, canvas, { width: S, height: S, px: opts.px || 2, fur: opts.fur, onFrame: onFrame, onEnd: onEnd });
        var unit = R.scale(), footOff = R.footOffset();
        var GRAV = 2600, WALK_SPEED = 0.9 * unit;

        var st = {
            x: 0, y: 0, vx: 0, vy: 0, mode: 'ground', posture: 'stand',
            pending: [], action: 'idle', until: 0, now: 0, walkTo: null,
            heading: 25, headingTarget: 25, dir: 1,
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
        function minX() { return S * 0.32; }
        function maxX() { return Math.max(minX(), area().w - S * 0.32); }

        st.x = typeof opts.x === 'number' ? opts.x : Math.min(maxX(), Math.max(minX(), area().w * 0.7));
        st.y = floorY();

        function place() {
            cs.transform = 'translate(' + Math.round(st.x - S / 2) + 'px,' + Math.round(st.y - (S - footOff)) + 'px)';
        }

        // ------------------------------------------------------- actions
        function play(id) { R.setAnim(id); }
        function startAction(name, dur) {
            var path = TRANS[st.posture + '>' + REQ[name]] || [];
            if (st.posture === REQ[name]) { path = []; }
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
            if (!auto) { startAction('idle', 1e9); return; }
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
                if (Math.abs(tx - st.x) < S * 0.4) { tx = st.x > (lo + hi) / 2 ? lo : hi; }
                st.walkTo = tx;
                st.dir = tx > st.x ? 1 : -1;
                st.headingTarget = 70 * st.dir;
                startAction('walk', 1e9);
                return;
            }
            st.walkTo = null;
            st.headingTarget = 25 * st.dir;
            var durs = { idle: 3 + Math.random() * 3, read: 6, wave: 4, lie: 5 + Math.random() * 4, tear: 99 };
            startAction(pick, durs[pick]);
        }

        // Called on every new animation frame (8 fps): stepped turning and walking.
        function onFrame(f, total, id) {
            if (st.mode === 'held' || st.mode === 'air') {
                st.headingTarget = 0;
            }
            if (st.heading !== st.headingTarget) {
                var d = st.headingTarget - st.heading;
                st.heading += Math.max(-45, Math.min(45, d));
                R.setAzimuth(-st.heading);
                return;
            }
            if (id === 'walk' && st.mode === 'ground' && st.walkTo !== null) {
                var step = WALK_SPEED / 8, rem = st.walkTo - st.x;
                if (Math.abs(rem) <= step) {
                    st.x = st.walkTo;
                    st.walkTo = null;
                    place();
                    decide();
                    return;
                }
                st.x += step * (rem > 0 ? 1 : -1);
                place();
            }
        }
        function onEnd(id) {
            if (st.mode !== 'ground') { return; }
            st.posture = ANIMS[id].posture;
            if (st.pending.length) { next(); } else { decide(); }
        }

        // ------------------------------------------------------ pointer
        function hit(lx, ly) {
            var cx = st.x, cy, rx, ry;
            if (st.posture === 'lie') { cy = st.y - 0.5 * unit; rx = 1.05 * unit; ry = 0.55 * unit; } else if (st.posture === 'fours' && st.mode === 'ground') { cy = st.y - 0.55 * unit; rx = 0.85 * unit; ry = 0.6 * unit; } else { cy = st.y - 0.85 * unit; rx = 0.62 * unit; ry = 0.9 * unit; }
            var dx = (lx - cx) / rx, dy = (ly - cy) / ry;
            return dx * dx + dy * dy <= 1;
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
                // Touch: long press (350 ms) picks him up, so scrolling still works.
                press = { id: e.pointerId, x: e.clientX, y: e.clientY, timer: global.setTimeout(function () { press = null; grab(p, e.pointerId); }, 350) };
                return;
            }
            grab(p, e.pointerId);
        }
        function grab(p, pointerId) {
            st.mode = 'held';
            st.pending = [];
            st.walkTo = null;
            st.grab = { dx: st.x - p.x, dy: st.y - p.y, id: pointerId };
            st.samples = [{ t: st.now, x: st.x, y: st.y }];
            st.vx = st.vy = 0;
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
            st.x = Math.min(a.w - S * 0.2, Math.max(S * 0.2, p.x + st.grab.dx));
            st.y = Math.min(floorY(), Math.max(S * 0.75, p.y + st.grab.dy));
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

        // ------------------------------------------------------ physics
        function physics(dt) {
            var fy = floorY();
            if (st.mode === 'air') {
                st.vy += GRAV * dt;
                st.x += st.vx * dt;
                st.y += st.vy * dt;
                var lo = S * 0.2, hi = area().w - S * 0.2;
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
        R.setAzimuth(-st.heading);
        place();
        startAction('idle', 2);
        st.raf = global.requestAnimationFrame(loop);

        return {
            // Plays an animation once (or for `seconds` if it loops), then wanders on.
            play: function (id, seconds) {
                if (!REQ[id] || st.mode !== 'ground') { return false; }
                st.walkTo = null;
                st.headingTarget = 25 * st.dir;
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

    global.docHamster3d = {
        ANIMS: ANIMS, ORDER: ORDER, VIEWS: VIEWS, frameCount: frameCount,
        createRenderer: createRenderer, mountMascot: mountMascot
    };
})(window);
