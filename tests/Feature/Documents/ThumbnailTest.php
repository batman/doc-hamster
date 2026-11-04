<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Thumbnail;
use Illuminate\Support\Facades\Storage;

/*
| #56: thumbnails respect the phone's EXIF orientation and skip images too
| large to decode during an upload.
*/

/** A 200 x 100 JPEG, optionally with an EXIF Orientation tag (big-endian TIFF, one IFD entry). */
function landscapeJpeg(?int $orientation = null): string
{
    $image = imagecreatetruecolor(200, 100);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    if ($orientation === null) {
        return $jpeg;
    }

    $tiff = "MM\x00\x2A\x00\x00\x00\x08"         // header, IFD at offset 8
        ."\x00\x01"                                // one entry
        ."\x01\x12\x00\x03\x00\x00\x00\x01"       // Orientation, SHORT, count 1
        .pack('n', $orientation)."\x00\x00"       // value, padded
        ."\x00\x00\x00\x00";                       // no next IFD
    $app1 = "Exif\x00\x00".$tiff;

    return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}

function storedJpeg(string $content): DocumentFile
{
    Storage::disk('local')->put('x/photo.jpg', $content);

    return new DocumentFile(['path' => 'x/photo.jpg', 'mime' => 'image/jpeg']);
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('turns a sideways phone photo upright', function (?int $orientation, int $width, int $height): void {
    $path = Thumbnail::make(storedJpeg(landscapeJpeg($orientation)));

    $size = getimagesizefromstring((string) Storage::disk('local')->get((string) $path));
    expect([$size[0] ?? 0, $size[1] ?? 0])->toBe([$width, $height]);
})->with([
    'no tag' => [null, 200, 100],
    'upright' => [1, 200, 100],
    'upside down' => [3, 200, 100],
    'turned right' => [6, 100, 200],
    'turned left' => [8, 100, 200],
]);

it('skips images above the pixel budget, and the command makes them later', function (): void {
    $file = storedJpeg(landscapeJpeg());

    expect(Thumbnail::make($file, maxPixels: 10_000))->toBeNull()
        ->and(Thumbnail::make($file, maxPixels: null))->toBe('x/photo.jpg.thumb.jpg');
});
