<?php

declare(strict_types=1);

use App\Domain\Documents\ReceiptDateFile;
use App\Domain\Optimize\ExifDate;
use App\Domain\Optimize\FileShrinker;
use App\Domain\Optimize\ImageShrinker;
use App\Domain\Optimize\Pdf\ScanPdf;
use App\Domain\Optimize\ShrinkResult;
use App\Domain\Optimize\SizeRule;
use App\Domain\ReceiptPack\Pdf\PdfCanvas;
use App\Domain\ReceiptPack\Pdf\PdfWriter;

/** A synthetic "scan": coloured or gray blocks with noise, as a big JPEG (quality 100). */
function optimizeNoise(int $width, int $height, bool $gray = false, int $quality = 100): string
{
    mt_srand(236);
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 250, 250, 250));
    for ($y = 0; $y < $height; $y += 3) {
        for ($x = 0; $x < $width; $x += 3) {
            $v = mt_rand(0, 255);
            $colour = $gray ? imagecolorallocate($image, $v, $v, $v) : imagecolorallocate($image, $v, mt_rand(0, 255), 255 - $v);
            imagefilledrectangle($image, $x, $y, $x + 2, $y + 2, (int) $colour);
        }
    }
    ob_start();
    imagejpeg($image, null, $quality);

    return (string) ob_get_clean();
}

/**
 * A scan PDF written like a scanner does: each page one JPEG picture placed by a matrix.
 *
 * @param  list<string>  $jpegs
 */
function optimizeScanPdf(array $jpegs, float $w = 144, float $h = 144): string
{
    $path = tempnam(sys_get_temp_dir(), 'scan');
    $writer = new PdfWriter($path);
    foreach ($jpegs as $jpeg) {
        $writer->scanPage($writer->jpeg($jpeg), 'Im0', "q {$w} 0 0 {$h} 0 0 cm /Im0 Do Q", [0, 0, $w, $h], null, 0);
    }
    $writer->finish();
    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

function optimizeTemp(string $bytes, string $extension): string
{
    $path = sys_get_temp_dir().'/opt-'.bin2hex(random_bytes(6)).'.'.$extension;
    file_put_contents($path, $bytes);

    return $path;
}

it('looks only at files whose average size per page is over the threshold (A406)', function (): void {
    $mb = 1024 * 1024;
    expect(SizeRule::overThreshold(5 * $mb, 2, 2 * $mb))->toBeTrue()
        ->and(SizeRule::overThreshold(4 * $mb, 2, 2 * $mb))->toBeFalse()
        ->and(SizeRule::overThreshold(3 * $mb, 1, 2 * $mb))->toBeTrue()
        ->and(SizeRule::overThreshold(30 * $mb, 20, 2 * $mb))->toBeFalse()
        ->and(SizeRule::overThreshold(3 * $mb, 0, 2 * $mb))->toBeFalse();
});

it('scales a page to 300 dpi of its page size and never up', function (): void {
    // A4 at 600 dpi → 300 dpi.
    expect(SizeRule::target(4960, 7016, 595.28, 841.89))->toBe([2480, 3508])
        // Landscape picture on a portrait page: long edge to long edge.
        ->and(SizeRule::target(7016, 4960, 595.28, 841.89))->toBe([3508, 2480])
        // Already 200 dpi: stays.
        ->and(SizeRule::target(1654, 2339, 595.28, 841.89))->toBe([1654, 2339])
        // A5 page at 600 dpi.
        ->and(SizeRule::target(3496, 4960, 419.53, 595.28))->toBe([1748, 2480])
        // A phone photo gets the pixels of an A4 page; a narrow till receipt keeps its width.
        ->and(SizeRule::photoTarget(4032, 3024))->toBe([3508, 2631])
        ->and(SizeRule::photoTarget(1000, 8000))->toBe([1000, 8000]);
});

it('keeps a result only when it is at least 30 % smaller (A406)', function (): void {
    expect(SizeRule::worthKeeping(1000, 700))->toBeTrue()
        ->and(SizeRule::worthKeeping(1000, 701))->toBeFalse()
        ->and(SizeRule::worthKeeping(1000, 0))->toBeFalse()
        ->and(SizeRule::worthKeeping(0, 0))->toBeFalse();
});

it('tells gray pages from coloured ones', function (): void {
    $gray = imagecreatefromstring(optimizeNoise(120, 90, gray: true));
    $colour = imagecreatefromstring(optimizeNoise(120, 90));
    assert($gray !== false && $colour !== false);

    expect(ImageShrinker::isGray($gray))->toBeTrue()
        ->and(ImageShrinker::isGray($colour))->toBeFalse();
});

it('takes plain scan PDFs and leaves born-digital, e-invoice and signed PDFs alone', function (): void {
    $scan = optimizeScanPdf([optimizeNoise(60, 60), optimizeNoise(60, 60, gray: true)]);
    expect(ScanPdf::pageCount($scan))->toBe(2);

    // Born-digital: text with fonts.
    $path = tempnam(sys_get_temp_dir(), 'text');
    $writer = new PdfWriter($path);
    $canvas = new PdfCanvas;
    $canvas->text(50, 50, 'Rechnung 2026-001');
    $writer->page($canvas);
    $writer->finish();
    $text = (string) file_get_contents($path);
    @unlink($path);
    expect(ScanPdf::pageCount($text))->toBeNull();

    // An e-invoice (embedded XML), a signed PDF, an encrypted one, an edited one (incremental update) and a page that
    // draws two pictures.
    $catalog = '1 0 obj'."\n".'<< /Type /Catalog /Pages 2 0 R >>';
    expect(ScanPdf::pageCount(str_replace($catalog, '1 0 obj'."\n".'<< /Type /Catalog /Pages 2 0 R /Names << /EmbeddedFiles << /Names [] >> >> >>', $scan)))->toBeNull()
        ->and(ScanPdf::pageCount(str_replace($catalog, '1 0 obj'."\n".'<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [] /SigFlags 3 >> >>', $scan)))->toBeNull()
        ->and(ScanPdf::pageCount($scan."trailer << /Root 1 0 R /Encrypt 9 0 R >>\n"))->toBeNull()
        ->and(ScanPdf::pageCount($scan."\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"))->toBeNull()
        ->and(ScanPdf::pageCount(str_replace('q 144 0 0 144 0 0 cm /Im0 Do Q', str_pad('/Im0 Do /Im0 Do', 30), $scan)))->toBeNull()
        ->and(ScanPdf::pageCount('%PDF-1.4 not really'))->toBeNull();
});

it('rebuilds a big scan smaller, with its pages, sizes and order', function (): void {
    $pages = [optimizeNoise(900, 900), optimizeNoise(900, 900, gray: true), optimizeNoise(900, 600)];
    $source = optimizeTemp(optimizeScanPdf($pages, 72, 72), 'pdf');
    $target = $source.'.out.pdf';

    $result = FileShrinker::shrink($source, 'application/pdf', 1000, $target);

    expect($result->status)->toBe(ShrinkResult::DONE)
        ->and($result->pages)->toBe(3)
        ->and($result->after)->toBeLessThanOrEqual((int) ($result->before * 0.7));
    $rebuilt = ScanPdf::read((string) file_get_contents($target));
    expect(count($rebuilt->pages))->toBe(3)
        // 72 pt = one inch → 300 px.
        ->and([$rebuilt->pages[0]->width, $rebuilt->pages[0]->height])->toBe([300, 300])
        // A picture of another shape keeps 300 dpi along its more generous edge.
        ->and([$rebuilt->pages[2]->width, $rebuilt->pages[2]->height])->toBe([450, 300])
        ->and($rebuilt->pages[1]->mediaBox)->toBe([0.0, 0.0, 72.0, 72.0]);
    @unlink($source);
    @unlink($target);
});

it('leaves a file under the threshold and one that would not get 30 % smaller', function (): void {
    $source = optimizeTemp(optimizeScanPdf([optimizeNoise(200, 200)], 72, 72), 'pdf');
    expect(FileShrinker::shrink($source, 'application/pdf', 50 * 1024 * 1024, $source.'.out')->status)->toBe(ShrinkResult::SMALL);
    @unlink($source);

    // Already small and at quality 60: nothing much to gain.
    $photo = optimizeTemp(optimizeNoise(300, 300, quality: 60), 'jpg');
    $result = FileShrinker::shrink($photo, 'image/jpeg', 1000, $photo.'.out.jpg');
    expect($result->status)->toBe(ShrinkResult::SKIP)
        ->and(is_file($photo.'.out.jpg'))->toBeFalse();
    @unlink($photo);
});

it('shrinks a photo and keeps its capture date', function (): void {
    $photo = optimizeTemp(ExifDate::insert(optimizeNoise(1200, 900), '2026:03:15 12:30:00'), 'jpg');
    $target = $photo.'.out.jpg';

    $result = FileShrinker::shrink($photo, 'image/jpeg', 1000, $target);

    expect($result->status)->toBe(ShrinkResult::DONE)
        ->and($result->mime)->toBe('image/jpeg');
    if (function_exists('exif_read_data')) {
        expect(ReceiptDateFile::exif($target))->toBe('2026-03-15');
    }
    @unlink($photo);
    @unlink($target);
});
