<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack;

use App\Domain\Documents\Thumbnail;
use App\Infrastructure\Environment\LocalOcr;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Turns the files of a receipt into picture pages for the pack (point 211.6): photos with GD (turned upright, at most
 * MAX_EDGE pixels, JPEG); PDFs page by page with the page renderer the text recognition already uses (app-owned
 * pdftopng or the system's pdftoppm), every page. Without a renderer – or for text documents, HEIC … – a note page
 * stands in its place and the receipt is listed at the end ("Originaldatei liegt im Zugang bei").
 */
final class PackImages
{
    /** Long edge of a picture in the pack: about 200 dpi on the picture area of an A4 page. */
    private const int MAX_EDGE = 1700;

    /** Pictures larger than this are not decoded (GD needs ~4 bytes per pixel). */
    private const int MAX_PIXELS = 60_000_000;

    /** PDF pages rendered per receipt. */
    private const int MAX_PDF_PAGES = 50;

    private const int DPI = 110;

    /** @var list<string> titles of receipts that are in the pack only as a note */
    public array $notShown = [];

    private int $counter = 0;

    public function __construct(private readonly string $dir) {}

    /**
     * @return list<PackPicture>
     */
    public function of(PackReceipt $receipt): array
    {
        $jpegs = [];
        $note = null;
        foreach ($receipt->files as $file) {
            $path = Storage::disk('local')->path($file['path']);
            [$found, $why] = match (true) {
                ! is_file($path) => [[], 'missing'],
                $file['mime'] === 'application/pdf' => $this->pdf($path),
                str_starts_with($file['mime'], 'image/') => $this->photo($path),
                default => [[], 'other'],
            };
            array_push($jpegs, ...$found);
            $note ??= $found === [] ? $why : null;
        }
        if ($jpegs === []) {
            $this->notShown[] = $receipt->title;

            return [new PackPicture($receipt->title, 1, 1, null, $note ?? 'other', $receipt->id)];
        }
        $pictures = [];
        foreach ($jpegs as $i => $jpeg) {
            $pictures[] = new PackPicture($receipt->title, $i + 1, count($jpegs), $jpeg, null, $receipt->id);
        }

        return $pictures;
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function photo(string $path): array
    {
        $size = @getimagesize($path);
        if ($size === false || $size[0] * $size[1] > self::MAX_PIXELS || ! function_exists('imagecreatefromstring')) {
            return [[], 'image'];
        }
        $jpeg = $this->toJpeg($path, $size[2] === IMAGETYPE_JPEG);

        return [$jpeg !== null ? [$jpeg] : [], 'image'];
    }

    /**
     * @return array{0: list<string>, 1: string}
     */
    private function pdf(string $path): array
    {
        $tool = null;
        try {
            $tool = app(LocalOcr::class)->renderTool();
        } catch (Throwable) {
        }
        if ($tool === null) {
            return [[], 'pdf'];
        }
        [$binary, $kind] = $tool;
        $root = $this->dir.'/r'.(++$this->counter);
        $process = new Process([$binary, '-f', '1', '-l', (string) self::MAX_PDF_PAGES, '-r', (string) self::DPI, ...($kind === 'pdftoppm' ? ['-png'] : []), $path, $root]);
        $process->setTimeout(180);
        try {
            $process->run();
        } catch (Throwable) {
            return [[], 'pdf'];
        }
        $pages = glob($root.'-*.png') ?: [];
        natsort($pages);
        $jpegs = [];
        foreach ($pages as $png) {
            $jpeg = $this->toJpeg($png, false);
            @unlink($png);
            if ($jpeg !== null) {
                $jpegs[] = $jpeg;
            }
        }

        return [$jpegs, 'pdf'];
    }

    /** A JPEG of at most MAX_EDGE pixels in the work folder; a fitting upright JPEG is copied as it is. */
    private function toJpeg(string $source, bool $isJpeg): ?string
    {
        $target = $this->dir.'/p'.(++$this->counter).'.jpg';
        $size = @getimagesize($source);
        $exif = $isJpeg && function_exists('exif_read_data') ? @exif_read_data($source) : false;
        $upright = ! is_array($exif) || ($exif['Orientation'] ?? 1) === 1;
        if ($isJpeg && $size !== false && $upright && max($size[0], $size[1]) <= self::MAX_EDGE && ($size['channels'] ?? 3) === 3) {
            return copy($source, $target) ? $target : null;
        }
        try {
            $image = @imagecreatefromstring((string) file_get_contents($source));
            if ($image === false) {
                return null;
            }
            if ($isJpeg) {
                $image = Thumbnail::upright($image, $source);
            }
            $scale = min(1, self::MAX_EDGE / max(imagesx($image), imagesy($image)));
            if ($scale < 1) {
                $scaled = imagescale($image, max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
                $image = $scaled === false ? $image : $scaled;
            }
            // Transparent PNGs on white, not black.
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

            return imagejpeg($flat, $target, 82) ? $target : null;
        } catch (Throwable) {
            return null;
        }
    }
}
