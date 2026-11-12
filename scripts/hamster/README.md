# Mascot GIF renderer

The README animations are rendered from the repo's own mascot engine
(`public/js/hamster-mascot.js` + `public/js/hamster-sprites.js`) — the same
code that animates the hamster in the app — so the docs can never drift from
the real visual language.

## Regenerate

Needs Node.js and Python 3 with Pillow (`pip install pillow`):

```sh
python3 scripts/hamster/render-gif.py
```

This rewrites `docs/assets/hamster-{wave,eat,sleep}.gif` (3x scale, 8 fps).
Other animation ids from `hamster-mascot.js` (`idle`, `read`, `error`, …)
render the same way: `python3 scripts/hamster/render-gif.py read`.

## How it works

1. `render-frames.mjs` loads the two engine scripts in Node with stubbed
   `window`/`requestAnimationFrame` and a recording 2D context, steps the
   animation frame by frame, and prints pixel data as JSON.
2. `render-gif.py` rasterizes the pixels with PIL and encodes the GIF
   (Pillow merges pixel-identical consecutive frames, keeping timing).
