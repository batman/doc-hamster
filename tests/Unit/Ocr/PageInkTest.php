<?php

declare(strict_types=1);

use App\Domain\Ocr\OcrSamplePage;
use App\Domain\Ocr\OcrWordMerge;
use App\Domain\Ocr\PageInk;

/*
| Point 184.4: a synthetic page with a heading rule and one line inside a thin box. The ruling lines go, the text
| stays; the boxed line is an ink area without words; the second read's words merge by position.
*/
it('removes the ruling lines and keeps the text pixels', function (bool $builtIn): void {
    $box = OcrSamplePage::BOX;
    $image = OcrSamplePage::make($builtIn);
    $ink = new PageInk($image);
    $textBefore = $ink->inkIn($box[0] + 10, $box[1] + 6, $box[2] - 10, $box[3] - 6);

    expect($ink->ink($box[0] + 300, $box[1]))->toBeTrue()
        ->and($ink->removeLines())->toBeGreaterThan(0)
        // The four sides of the box and the heading rule are white now …
        ->and($ink->inkIn($box[0] - 1, $box[1] - 1, $box[2] + 1, $box[1] + 1))->toBe(0)
        ->and($ink->inkIn($box[0] - 1, $box[3] - 1, $box[2] + 1, $box[3] + 1))->toBe(0)
        ->and($ink->inkIn($box[0] - 1, $box[1], $box[0] + 1, $box[3]))->toBe(0)
        ->and($ink->inkIn($box[2] - 1, $box[1], $box[2] + 1, $box[3]))->toBe(0)
        ->and($ink->inkIn(500, 140, 1400, 142))->toBe(0)
        // … the text inside the box is untouched, also in the image itself.
        ->and($ink->inkIn($box[0] + 10, $box[1] + 6, $box[2] - 10, $box[3] - 6))->toBe($textBefore)
        ->and($textBefore)->toBeGreaterThan(500)
        ->and((new PageInk($image))->inkIn($box[0] + 10, $box[1] + 6, $box[2] - 10, $box[3] - 6))->toBe($textBefore);
})->with(['ttf or built-in font' => false, 'built-in font' => true]);

it('leaves a filled bar alone', function (): void {
    $image = imagecreatetruecolor(800, 600);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imagefilledrectangle($image, 50, 100, 750, 160, (int) imagecolorallocate($image, 0, 0, 0));
    $ink = new PageInk($image);

    expect($ink->removeLines())->toBe(0)->and($ink->inkIn(50, 100, 750, 160))->toBe(701 * 61);
});

it('finds the boxed line as an ink area without words', function (): void {
    $box = OcrSamplePage::BOX;
    $ink = new PageInk(OcrSamplePage::make(true));
    $ink->removeLines();
    $w = OcrSamplePage::WIDTH;
    $h = OcrSamplePage::HEIGHT;
    // The first read found every line but the boxed one: one word box per line over its ink.
    $words = [];
    foreach ($ink->regions() as $l => $r) {
        if ($r[1] > $box[1]) {
            continue;
        }
        $words[] = ['t' => 'w'.$l, 'x' => $r[0] / $w, 'y' => $r[1] / $h, 'w' => ($r[2] - $r[0]) / $w, 'h' => ($r[3] - $r[1]) / $h, 'c' => 90, 'l' => $l, 'b' => 0];
    }
    $missing = $ink->uncovered(['n' => 1, 'words' => $words]);

    expect($words)->toHaveCount(4)->and($missing)->toHaveCount(1)
        ->and($missing[0][1])->toBeGreaterThan($box[1])->and($missing[0][3])->toBeLessThan($box[3]);

    // Everything but the missing area goes white for the PSM 11 read.
    $ink->keepOnly($missing);
    $sparse = new PageInk($ink->image());
    expect($sparse->inkIn(0, 0, $w - 1, $box[1]))->toBe(0)
        ->and($sparse->inkIn(0, $box[3], $w - 1, $h - 1))->toBe(0)
        ->and($sparse->inkIn($missing[0][0], $missing[0][1], $missing[0][2], $missing[0][3]))->toBeGreaterThan(500);
});

it('merges the second read by position without duplicates', function (): void {
    $word = static fn (string $t, float $x, float $y, int $l, int $c = 90, int $b = 0): array => ['t' => $t, 'x' => $x, 'y' => $y, 'w' => 0.1, 'h' => 0.03, 'c' => $c, 'l' => $l, 'b' => $b];
    $page = ['n' => 1, 'words' => [
        $word('Rechnung', 0.1, 0.1, 0), $word('Max', 0.1, 0.2, 1), $word('Mustermann', 0.25, 0.2, 1), $word('Danke', 0.1, 0.8, 2, 90, 1),
    ]];
    $extra = ['n' => 1, 'words' => [
        $word('DE00', 0.25, 0.6, 0, 43), $word('IBAN', 0.1, 0.6, 0), $word('0000', 0.4, 0.605, 0), // unsure in a sure line
        $word('Max', 0.101, 0.201, 1),      // the same word again
        $word('~', 0.7, 0.6, 0),            // no letter
        $word('xq', 0.8, 0.7, 0, 20),       // a line unsure as a whole
    ]];

    [$merged, $added] = OcrWordMerge::merge($page, $extra);

    expect($added)->toBe(3)
        ->and(array_column($merged['words'], 't'))->toBe(['Rechnung', 'Max', 'Mustermann', 'IBAN', 'DE00', '0000', 'Danke'])
        ->and(array_column($merged['words'], 'l'))->toBe([0, 1, 1, 2, 2, 2, 3])
        ->and(array_column($merged['words'], 'b'))->toBe([0, 0, 0, 2, 2, 2, 1]);
    expect(OcrWordMerge::merge($page, null))->toBe([$page, 0]);
});
