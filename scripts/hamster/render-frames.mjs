// Drives the repo's own mascot engine (public/js/hamster-*.js) without a
// browser and dumps each animation frame as pixel data.
//
// Usage: node render-frames.mjs <animId> [maxFrames]
// Prints JSON: {"w":112,"h":104,"fps":8,"frames":[[[x,y,color],...], ...]}
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

// The engine scripts are plain browser scripts; give them the globals they expect.
globalThis.window = globalThis;
globalThis.requestAnimationFrame = () => 0;
globalThis.cancelAnimationFrame = () => {};

function loadJs(rel) {
    const code = fs.readFileSync(path.join(repoRoot, rel), 'utf8');
    (0, eval)(code + '\n//# sourceURL=' + rel);
}
loadJs('public/js/hamster-sprites.js');
loadJs('public/js/hamster-mascot.js');

const animId = process.argv[2] || 'idle';
const maxFrames = parseInt(process.argv[3] || '400', 10);

let pixels = [];
const ctx = {
    _fill: '#000000',
    set fillStyle(v) { this._fill = v; },
    get fillStyle() { return this._fill; },
    fillRect(x, y) { pixels.push([x, y, this._fill]); },
    clearRect() { pixels = []; },
};
const canvas = { width: 0, height: 0, getContext: () => ctx };

let total = 0;
const renderer = globalThis.docHamster2d.createRenderer(canvas, {
    fps: 8,
    onFrame(f, t) { total = t; },
});
renderer.setAnim(animId);
const frames = [];
renderer.tick(0);
frames.push(pixels);
for (let i = 1; i < total && i < maxFrames; i++) {
    renderer.step(1);
    renderer.tick(0);
    frames.push(pixels);
}
process.stdout.write(JSON.stringify({ w: canvas.width, h: canvas.height, fps: 8, frames }));
