#!/usr/bin/env python3
"""Render mascot animations to GIFs using the repo's own engine.

Frames come from render-frames.mjs (drives public/js/hamster-mascot.js
without a browser); this script only rasterizes them with PIL.

Usage:
    python3 scripts/hamster/render-gif.py [--scale 3] [--fps 8] [anim ...]

Default anims (the ones README.md uses): wave eat sleep.
Output: docs/assets/hamster-<anim>.gif
"""
import argparse
import json
import subprocess
import sys
from pathlib import Path

try:
    from PIL import Image
except ImportError:
    sys.exit('Pillow is required: pip install pillow')

HERE = Path(__file__).resolve().parent
RENDER_JS = HERE / 'render-frames.mjs'
OUT_DIR = HERE / '..' / '..' / 'docs' / 'assets'

DEFAULT_ANIMS = ('wave', 'eat', 'sleep')


def parse_color(s):
    s = s.strip()
    if s.startswith('#'):
        h = s[1:]
        if len(h) == 3:
            h = ''.join(c * 2 for c in h)
        return (int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16), 255)
    if s.startswith('rgb('):
        parts = s[4:-1].split(',')
        return (int(parts[0]), int(parts[1]), int(parts[2]), 255)
    raise ValueError('unsupported color: ' + s)


def frames_for(anim):
    raw = subprocess.run(
        ['node', str(RENDER_JS), anim], capture_output=True, text=True,
        check=True).stdout
    return json.loads(raw)


def rasterize(data, scale):
    w, h = data['w'] * scale, data['h'] * scale
    imgs = []
    for pixels in data['frames']:
        img = Image.new('RGBA', (w, h), (0, 0, 0, 0))
        px = img.load()
        for x, y, c in pixels:
            rgba = parse_color(c)
            for dx in range(scale):
                for dy in range(scale):
                    px[x * scale + dx, y * scale + dy] = rgba
        imgs.append(img)
    return imgs


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('anims', nargs='*', default=list(DEFAULT_ANIMS))
    parser.add_argument('--scale', type=int, default=3)
    parser.add_argument('--fps', type=int, default=8)
    args = parser.parse_args()

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    for anim in args.anims:
        imgs = rasterize(frames_for(anim), args.scale)
        path = OUT_DIR / f'hamster-{anim}.gif'
        imgs[0].save(path, save_all=True, append_images=imgs[1:],
                     duration=1000 // args.fps, loop=0, disposal=2)
        print(f'{path}: {len(imgs)} frames')


if __name__ == '__main__':
    main()
