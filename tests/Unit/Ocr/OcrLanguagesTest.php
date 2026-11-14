<?php

declare(strict_types=1);

use App\Domain\Ocr\OcrTextQuality;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Extensions\Catalogue;
use App\Infrastructure\Extensions\Extension;

/*
| Points 171, 173, A345, A346: catalogue pins, language string building, the broken-text rule.
*/

it('pins every catalogue data pack and keeps modules out of install (173.1, 173.3)', function (): void {
    foreach (Catalogue::all() as $key => $ext) {
        expect($ext->key)->toBe($key);
        if ($ext->kind === Extension::DATA) {
            expect($ext->installable())->toBeTrue()
                ->and($ext->size())->toBeGreaterThan(100_000);
            foreach ($ext->files as $file) {
                expect($file['url'])->toStartWith('https://github.com/tesseract-ocr/tessdata_fast/raw/4.1.0/')
                    ->and($file['sha256'])->toMatch('/^[0-9a-f]{64}$/');
            }
        } else {
            expect($ext->installable())->toBeFalse();
        }
    }
    expect(Catalogue::find('ocr-ell')?->meta['lang'])->toBe('ell')
        ->and(Catalogue::find('ocr-deu'))->toBeNull();
});

it('rejects unsafe catalogue entries', function (array $file): void {
    new Extension('ocr-x', Extension::DATA, 'ocr', '1', [$file], 'ocr/tessdata');
})->with([
    'path in name' => [['name' => '../x.traineddata', 'url' => 'https://example.com/x', 'sha256' => str_repeat('a', 64), 'size' => 1]],
    'plain http' => [['name' => 'x.traineddata', 'url' => 'http://example.com/x', 'sha256' => str_repeat('a', 64), 'size' => 1]],
    'bad hash' => [['name' => 'x.traineddata', 'url' => 'https://example.com/x', 'sha256' => 'abc', 'size' => 1]],
])->throws(InvalidArgumentException::class);

it('builds the language set: all enabled, or the document choice when installed (171.2, A345)', function (): void {
    $enabled = ['deu', 'eng', 'ell'];
    $installed = ['deu', 'eng', 'ell', 'fra'];

    expect(implode('+', LocalOcr::pickLanguages($enabled, null, $installed)))->toBe('deu+eng+ell')
        ->and(LocalOcr::pickLanguages($enabled, 'fra', $installed))->toBe(['fra'])
        ->and(LocalOcr::pickLanguages($enabled, 'deu+ell+ell', $installed))->toBe(['deu', 'ell'])
        ->and(LocalOcr::pickLanguages($enabled, 'tur', $installed))->toBe($enabled)
        ->and(LocalOcr::pickLanguages($enabled, '../x', $installed))->toBe($enabled);
});

it('flags broken texts and keeps normal receipts (A346)', function (): void {
    $receipt = "Muster GmbH Hauptstraße 1 12345 Berlin\nRechnung Nr. 2026-0042 vom 12.03.2026\nBüromaterial 2 x 12,50 € 25,00 €\nSumme netto 25,00 € MwSt 19% 4,75 € Gesamt 29,75 €\nVielen Dank für Ihren Einkauf";
    $junk = '~|} ¦«» ^^ ]]{ §§ ¬¬ |~| }{ ¦¦ «« »» ~~ ^| ]{ §¬ ~|} ¦«»';
    $confetti = 'a b cd e fg h ij k lm n op q rs t uv';
    $greekAsLatin = 'AoyaplaoytoU TlpoïovtwV EkboonG TImoAoyiou ApiBuoG MeplypaonV TloooTnTa EmrtpoonG KaBapnG AEiaG ZuvoAikoV';

    expect(OcrTextQuality::looksBroken($receipt))->toBeFalse()
        ->and(OcrTextQuality::looksBroken('kurz'))->toBeFalse()
        ->and(OcrTextQuality::looksBroken($junk))->toBeTrue()
        ->and(OcrTextQuality::looksBroken($confetti))->toBeTrue()
        ->and(OcrTextQuality::looksBroken($greekAsLatin))->toBeTrue();
});
