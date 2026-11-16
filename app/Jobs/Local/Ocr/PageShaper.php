<?php

declare(strict_types=1);

namespace App\Jobs\Local\Ocr;

use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\PageImage;
use App\Domain\Documents\PageTransform;
use App\Domain\Ocr\ShownWords;
use RuntimeException;

/**
 * Point 238 (A415/A417/A418): the text recognition reads the page as it is shown – turned and cropped – so word
 * boxes are in shown coordinates and Tesseract sees upright lines. Words read from the original page (a PDF's text
 * layer, ocrmypdf) are moved onto the shown page. On the first read of a new upload, a page without a rotation of
 * its own is turned when Tesseract's orientation detection is sure (A418); found() lists those for storing.
 */
final class PageShaper
{
    /** @var array<int, PageTransform> */
    private array $found = [];

    /** @var array<int, true> pages the orientation detection has looked at */
    private array $checked = [];

    /** @var list<string> */
    private array $temp = [];

    public function __construct(private readonly DocumentFile $file, private readonly ?Orientation $osd = null) {}

    /** The rotation/crop page $n is read with. */
    public function transform(int $n): PageTransform
    {
        return $this->found[$n] ?? $this->file->pageTransform($n);
    }

    /** @return array<int, PageTransform> pages turned by the orientation detection */
    public function found(): array
    {
        return $this->found;
    }

    /** The picture Tesseract reads for page $n: the shown page as a temporary PNG, or the image itself. */
    public function input(string $image, int $n, bool $jpeg, Deadline $deadline): string
    {
        if ($this->osd !== null && ! isset($this->checked[$n]) && ! array_key_exists($n, $this->file->transforms())) {
            $this->checked[$n] = true;
            $upright = $jpeg && self::exifTurned($image) ? $this->keep(PageImage::shownPng($image, PageTransform::none(), true)) : null;
            $angle = $this->osd->rotation($upright ?? $image, $deadline);
            if ($angle !== 0) {
                $this->found[$n] = PageTransform::make($angle);
            }
        }
        $transform = $this->transform($n);
        if ($transform->isNone()) {
            return $image;
        }

        return $this->keep(PageImage::shownPng($image, $transform, $jpeg)) ?? throw new RuntimeException('gd: Bild nicht lesbar');
    }

    /**
     * A page read from the original (text layer, ocrmypdf) moved onto the shown page.
     *
     * @param  array{n: int, words: list<array<string, mixed>>}|null  $page
     * @return array{n: int, words: list<array<string, mixed>>}|null
     */
    public function fromOriginal(?array $page): ?array
    {
        return $page === null ? null : ShownWords::page($page, PageTransform::none(), $this->transform($page['n']));
    }

    /**
     * @param  list<array{n: int, words: list<array<string, mixed>>}>  $pages
     * @return list<array{n: int, words: list<array<string, mixed>>}>
     */
    public function allFromOriginal(array $pages): array
    {
        return array_map(fn (array $page): array => ShownWords::page($page, PageTransform::none(), $this->transform($page['n'])), $pages);
    }

    public function cleanup(): void
    {
        OcrTools::unlink($this->temp);
        $this->temp = [];
    }

    private function keep(?string $temp): ?string
    {
        if ($temp !== null) {
            $this->temp[] = $temp;
        }

        return $temp;
    }

    private static function exifTurned(string $image): bool
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($image) : false;

        return is_array($exif) && ($exif['Orientation'] ?? 1) !== 1;
    }
}
