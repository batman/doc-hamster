<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/**
 * Rotation and crop of one page (point 238, A414): non-destructive, the stored file never changes.
 *
 * Coordinates: the "original" page is the page as the browser shows the stored file (a photo turned upright by its
 * EXIF note, a PDF page as pdf.js draws it), page-relative 0..1 with the origin top-left. The crop box {x, y, w, h}
 * is defined in these original, unrotated coordinates; the rotation (0/90/180/270, clockwise) turns the cropped part.
 * So the shown page = crop first, then turn. The "shown" page is what the viewer, thumbnail, receipt pack and OCR
 * see; OCR word boxes (documents.ocr_words) are stored in shown coordinates.
 */
final readonly class PageTransform
{
    /** Smallest crop side (page-relative): a box below this is no sensible part of a receipt. */
    public const float MIN_SIDE = 0.02;

    /** A crop this close to the full page is no crop. */
    private const float EPS = 0.0005;

    /**
     * @param  int  $rotation  0, 90, 180 or 270 – clockwise
     * @param  array{x: float, y: float, w: float, h: float}|null  $crop  in original page coordinates, null = whole page
     */
    private function __construct(public int $rotation, public ?array $crop) {}

    public static function none(): self
    {
        return new self(0, null);
    }

    /**
     * Any angle is turned into the next quarter turn (−90 → 270, 450 → 90); the crop box ({x, y, w, h} or [x, y, w, h])
     * is clamped.
     *
     * @param  array<mixed>|null  $crop
     */
    public static function make(int $rotation, ?array $crop = null): self
    {
        $rotation = (int) (round((((($rotation % 360) + 360) % 360) / 90)) * 90) % 360;
        $box = $crop === null ? null : self::clampCrop(
            self::num($crop['x'] ?? $crop[0] ?? 0), self::num($crop['y'] ?? $crop[1] ?? 0),
            self::num($crop['w'] ?? $crop[2] ?? 1), self::num($crop['h'] ?? $crop[3] ?? 1),
        );

        return new self($rotation, $box);
    }

    /**
     * The crop box kept inside the page with sides of at least MIN_SIDE; null when it is (about) the whole page.
     *
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    public static function clampCrop(float $x, float $y, float $w, float $h): ?array
    {
        if ($w < 0) {
            [$x, $w] = [$x + $w, -$w];
        }
        if ($h < 0) {
            [$y, $h] = [$y + $h, -$h];
        }
        $x = min(1 - self::MIN_SIDE, max(0.0, $x));
        $y = min(1 - self::MIN_SIDE, max(0.0, $y));
        $w = min(1 - $x, max(self::MIN_SIDE, $w));
        $h = min(1 - $y, max(self::MIN_SIDE, $h));
        if ($x <= self::EPS && $y <= self::EPS && $x + $w >= 1 - self::EPS && $y + $h >= 1 - self::EPS) {
            return null;
        }

        return ['x' => round($x, 6), 'y' => round($y, 6), 'w' => round($w, 6), 'h' => round($h, 6)];
    }

    /** From the stored form {"r": 90, "c": [x, y, w, h]}; anything malformed is no transform. */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            return self::none();
        }
        $crop = is_array($data['c'] ?? null) && count($data['c']) === 4 ? array_values($data['c']) : null;

        return self::make(is_numeric($data['r'] ?? null) ? (int) $data['r'] : 0, $crop);
    }

    /** @return array{r: int, c: list<float>|null}|null null for no transform */
    public function toArray(): ?array
    {
        if ($this->isNone()) {
            return null;
        }

        return ['r' => $this->rotation, 'c' => $this->crop === null ? null : array_values($this->crop)];
    }

    public function isNone(): bool
    {
        return $this->rotation === 0 && $this->crop === null;
    }

    /**
     * Original page point → shown page point (may lie outside 0..1 when cropped away).
     *
     * @return array{0: float, 1: float}
     */
    public function toShown(float $u, float $v): array
    {
        $c = $this->crop ?? ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0];
        $a = ($u - $c['x']) / $c['w'];
        $b = ($v - $c['y']) / $c['h'];

        return match ($this->rotation) {
            90 => [1 - $b, $a],
            180 => [1 - $a, 1 - $b],
            270 => [$b, 1 - $a],
            default => [$a, $b],
        };
    }

    /**
     * Shown page point → original page point.
     *
     * @return array{0: float, 1: float}
     */
    public function toOriginal(float $s, float $t): array
    {
        [$a, $b] = match ($this->rotation) {
            90 => [$t, 1 - $s],
            180 => [1 - $s, 1 - $t],
            270 => [1 - $t, $s],
            default => [$s, $t],
        };
        $c = $this->crop ?? ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0];

        return [$c['x'] + $a * $c['w'], $c['y'] + $b * $c['h']];
    }

    /**
     * A box of the original page in shown coordinates (quarter turns keep boxes axis-aligned).
     *
     * @param  array{x: float, y: float, w: float, h: float}  $box
     * @return array{x: float, y: float, w: float, h: float}
     */
    public function boxToShown(array $box): array
    {
        return self::corners($this->toShown(...), $box);
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float}  $box
     * @return array{x: float, y: float, w: float, h: float}
     */
    public function boxToOriginal(array $box): array
    {
        return self::corners($this->toOriginal(...), $box);
    }

    /**
     * Pixel size of the shown page for an original page of $width × $height.
     *
     * @return array{0: int, 1: int}
     */
    public function shownSize(int $width, int $height): array
    {
        [, , $w, $h] = $this->cropPixels($width, $height);

        return $this->rotation % 180 === 0 ? [$w, $h] : [$h, $w];
    }

    /**
     * The crop box in pixels of an original page of $width × $height: x, y, w, h (at least 1 × 1, inside the page).
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public function cropPixels(int $width, int $height): array
    {
        $c = $this->crop ?? ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0];
        $x = min($width - 1, max(0, (int) round($c['x'] * $width)));
        $y = min($height - 1, max(0, (int) round($c['y'] * $height)));
        $w = max(1, min($width - $x, (int) round($c['w'] * $width)));
        $h = max(1, min($height - $y, (int) round($c['h'] * $height)));

        return [$x, $y, $w, $h];
    }

    /**
     * @param  callable(float, float): array{0: float, 1: float}  $map
     * @param  array{x: float, y: float, w: float, h: float}  $box
     * @return array{x: float, y: float, w: float, h: float}
     */
    private static function corners(callable $map, array $box): array
    {
        [$x1, $y1] = $map((float) $box['x'], (float) $box['y']);
        [$x2, $y2] = $map((float) $box['x'] + (float) $box['w'], (float) $box['y'] + (float) $box['h']);

        return ['x' => min($x1, $x2), 'y' => min($y1, $y2), 'w' => abs($x2 - $x1), 'h' => abs($y2 - $y1)];
    }

    private static function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
