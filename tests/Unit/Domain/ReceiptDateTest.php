<?php

declare(strict_types=1);

use App\Domain\Documents\ReceiptDate;
use App\Domain\Documents\ReceiptDateFile;
use Carbon\CarbonImmutable;
use Tests\Support\ReceiptFiles;

/*
| Point 214, A381: which invoice date becomes the Belegdatum, and the dates a photo or PDF carries itself.
*/

it('takes a confirmed invoice date always and a recognised one only when plausible', function (?string $date, ?string $state, ?array $expected): void {
    $today = CarbonImmutable::parse('2026-11-14');

    expect(ReceiptDate::fromProperty($date, $state, $today))->toBe($expected);
})->with([
    'confirmed' => ['2026-03-01', 'confirmed', ['2026-03-01', 'confirmed']],
    'confirmed in the future' => ['2041-03-01', 'confirmed', ['2041-03-01', 'confirmed']],
    'recognised' => ['2026-03-01', 'suggested', ['2026-03-01', 'recognised']],
    'recognised today' => ['2026-11-14', 'suggested', ['2026-11-14', 'recognised']],
    'recognised tomorrow' => ['2026-11-15', 'suggested', null],
    'recognised ten years back' => ['2016-11-14', 'suggested', ['2016-11-14', 'recognised']],
    'recognised older' => ['2016-11-13', 'suggested', null],
    'none' => [null, null, null],
]);

it('reads the capture date of a photo from its EXIF block', function (): void {
    if (! function_exists('exif_read_data')) {
        $this->markTestSkipped('exif extension missing');
    }
    $path = tempnam(sys_get_temp_dir(), 'dh-exif');
    file_put_contents($path, ReceiptFiles::jpegWithExif('2024:03:15 12:00:00'));

    expect(ReceiptDateFile::read($path, 'image/jpeg'))->toBe(['2024-03-15', ReceiptDate::EXIF]);
    @unlink($path);
});

it('reads the creation date of a PDF and nothing from other files', function (): void {
    $pdf = tempnam(sys_get_temp_dir(), 'dh-pdf');
    file_put_contents($pdf, ReceiptFiles::pdfCreated('20230702'));
    $png = tempnam(sys_get_temp_dir(), 'dh-png');
    file_put_contents($png, ReceiptFiles::png());

    expect(ReceiptDateFile::read($pdf, 'application/pdf'))->toBe(['2023-07-02', ReceiptDate::PDF])
        ->and(ReceiptDateFile::read($png, 'image/png'))->toBeNull()
        ->and(ReceiptDateFile::read($png, 'image/jpeg'))->toBeNull()
        ->and(ReceiptDateFile::read($pdf.'-missing', 'application/pdf'))->toBeNull();
    @unlink($pdf);
    @unlink($png);
});
