<?php

declare(strict_types=1);

use App\Domain\Documents\PageImage;
use App\Domain\Documents\PageTransform;
use App\Domain\Ocr\ShownWords;
use App\Jobs\Local\Ocr\Orientation;

/** Point 238: rotation + crop math (crop in original coordinates first, then turn clockwise). */
function ptNear(array $a, array $b): void
{
    foreach ($b as $k => $v) {
        expect($a[$k])->toEqualWithDelta($v, 1e-9);
    }
}

it('normalises rotations to quarter turns', function (int $in, int $out): void {
    expect(PageTransform::make($in)->rotation)->toBe($out);
})->with([[0, 0], [90, 90], [-90, 270], [450, 90], [180, 180], [-270, 90], [360, 0], [89, 90]]);

it('maps original points to the shown page for every quarter turn', function (int $r, array $shown): void {
    // The original top-left corner and a point near the top right.
    $t = PageTransform::make($r);
    ptNear($t->toShown(0.0, 0.0), $shown[0]);
    ptNear($t->toShown(0.9, 0.1), $shown[1]);
})->with([
    'none' => [0, [[0, 0], [0.9, 0.1]]],
    'right' => [90, [[1, 0], [0.9, 0.9]]],
    'upside down' => [180, [[1, 1], [0.1, 0.9]]],
    'left' => [270, [[0, 1], [0.1, 0.1]]],
]);

it('maps there and back with crop and rotation', function (int $r): void {
    $t = PageTransform::make($r, ['x' => 0.1, 'y' => 0.2, 'w' => 0.5, 'h' => 0.6]);
    foreach ([[0.1, 0.2], [0.35, 0.5], [0.6, 0.8], [0.42, 0.33]] as [$u, $v]) {
        [$s, $q] = $t->toShown($u, $v);
        ptNear($t->toOriginal($s, $q), [$u, $v]);
    }
    // The crop box itself is the whole shown page.
    ptNear($t->boxToShown(['x' => 0.1, 'y' => 0.2, 'w' => 0.5, 'h' => 0.6]), ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);
})->with([0, 90, 180, 270]);

it('turns boxes and keeps them axis-aligned', function (): void {
    $t = PageTransform::make(90);
    // A word at the top left of the original lies at the top right after a right turn.
    ptNear($t->boxToShown(['x' => 0.1, 'y' => 0.05, 'w' => 0.3, 'h' => 0.02]), ['x' => 0.93, 'y' => 0.1, 'w' => 0.02, 'h' => 0.3]);
    ptNear($t->boxToOriginal(['x' => 0.93, 'y' => 0.1, 'w' => 0.02, 'h' => 0.3]), ['x' => 0.1, 'y' => 0.05, 'w' => 0.3, 'h' => 0.02]);
});

it('clamps the crop box into the page with a minimum size', function (): void {
    expect(PageTransform::clampCrop(0, 0, 1, 1))->toBeNull()
        ->and(PageTransform::clampCrop(-0.2, -0.1, 1.5, 2))->toBeNull()
        ->and(PageTransform::clampCrop(0.5, 0.5, 0.8, 0.8))->toBe(['x' => 0.5, 'y' => 0.5, 'w' => 0.5, 'h' => 0.5])
        ->and(PageTransform::clampCrop(0.2, 0.3, 0.001, 0))->toBe(['x' => 0.2, 'y' => 0.3, 'w' => PageTransform::MIN_SIDE, 'h' => PageTransform::MIN_SIDE])
        ->and(PageTransform::clampCrop(1.2, 0.99, 0.5, 0.5))->toBe(['x' => 0.98, 'y' => 0.98, 'w' => 0.02, 'h' => 0.02])
        // A box drawn backwards (negative size) counts from its other corner.
        ->and(PageTransform::clampCrop(0.6, 0.7, -0.4, -0.5))->toBe(['x' => 0.2, 'y' => 0.2, 'w' => 0.4, 'h' => 0.5]);
});

it('stores and reads its compact form', function (): void {
    $t = PageTransform::make(270, [0.1, 0.2, 0.3, 0.4]);
    expect($t->toArray())->toBe(['r' => 270, 'c' => [0.1, 0.2, 0.3, 0.4]])
        ->and(PageTransform::fromArray($t->toArray())->toArray())->toBe($t->toArray())
        ->and(PageTransform::make(0)->toArray())->toBeNull()
        ->and(PageTransform::fromArray('nonsense')->isNone())->toBeTrue()
        ->and(PageTransform::fromArray(['r' => 90, 'c' => [1, 2]])->toArray())->toBe(['r' => 90, 'c' => null]);
});

it('gives the crop in pixels and the shown size', function (): void {
    $t = PageTransform::make(90, [0.25, 0.1, 0.5, 0.8]);
    expect($t->cropPixels(800, 1000))->toBe([200, 100, 400, 800])
        ->and($t->shownSize(800, 1000))->toBe([800, 400])
        ->and(PageTransform::none()->shownSize(800, 1000))->toBe([800, 1000]);
});

it('moves word boxes from one shown page to another and drops cropped-away words', function (): void {
    $page = ['n' => 1, 'words' => [
        ['t' => 'Rechnung', 'x' => 0.1, 'y' => 0.05, 'w' => 0.3, 'h' => 0.02],
        ['t' => 'Summe', 'x' => 0.6, 'y' => 0.9, 'w' => 0.2, 'h' => 0.02],
    ]];
    $right = PageTransform::make(90);
    $moved = ShownWords::page($page, PageTransform::none(), $right);
    ptNear($moved['words'][0], ['x' => 0.93, 'y' => 0.1, 'w' => 0.02, 'h' => 0.3]);
    expect($moved['words'][0]['t'])->toBe('Rechnung');
    // And back again.
    ptNear(ShownWords::page($moved, $right, PageTransform::none())['words'][1], ['x' => 0.6, 'y' => 0.9, 'w' => 0.2, 'h' => 0.02]);
    // Cropped to the top half: "Summe" goes, "Rechnung" is stretched to the new page.
    $top = ShownWords::page($page, PageTransform::none(), PageTransform::make(0, [0, 0, 1, 0.5]));
    expect($top['words'])->toHaveCount(1);
    ptNear($top['words'][0], ['x' => 0.1, 'y' => 0.1, 'w' => 0.3, 'h' => 0.04]);
});

it('moves corrections of the changed page only', function (): void {
    $fixes = [['p' => 1, 'x' => 0.1, 'y' => 0.1, 'w' => 0.1, 'h' => 0.1, 't' => 'A'], ['p' => 2, 'x' => 0.1, 'y' => 0.1, 'w' => 0.1, 'h' => 0.1, 't' => 'B']];
    $out = ShownWords::fixes($fixes, 1, PageTransform::none(), PageTransform::make(180));
    ptNear($out[0], ['x' => 0.8, 'y' => 0.8, 'w' => 0.1, 'h' => 0.1]);
    expect($out[1])->toBe($fixes[1]);
});

it('crops and turns a picture with GD', function (): void {
    $image = imagecreatetruecolor(200, 100);
    // A red block in the top-left quarter.
    imagefilledrectangle($image, 0, 0, 99, 49, (int) imagecolorallocate($image, 255, 0, 0));
    $out = PageImage::apply($image, PageTransform::make(90, [0, 0, 0.5, 1]));
    expect([imagesx($out), imagesy($out)])->toBe([100, 100]);
    // Turned right, the top-left red block lies top right.
    expect(imagecolorat($out, 90, 10) >> 16 & 0xFF)->toBe(255)
        ->and(imagecolorat($out, 10, 10) >> 16 & 0xFF)->toBe(0);
});

it('reads Tesseract orientation output only when it is sure', function (): void {
    $sure = "Page number: 0\nOrientation in degrees: 270\nRotate: 90\nOrientation confidence: 7.52\nScript: Latin\nScript confidence: 2.10\n";
    expect(Orientation::parse($sure))->toBe(90)
        ->and(Orientation::parse(str_replace('7.52', '0.80', $sure)))->toBe(0)
        ->and(Orientation::parse(str_replace('Rotate: 90', 'Rotate: 0', $sure)))->toBe(0)
        ->and(Orientation::parse('Too few characters. Skipping this page'))->toBe(0);
});
