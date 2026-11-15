<?php

declare(strict_types=1);

namespace App\Domain\Optimize\Pdf;

use RuntimeException;

/**
 * Point 236: whether a PDF is a plain scan the optimiser may rebuild, and its pages if so. A scan here is a PDF whose
 * every page shows exactly one JPEG picture (DCTDecode, gray or RGB) and nothing else: no fonts (so no text and no
 * text layer), no form fields, no annotations, no embedded files (ZUGFeRD / XRechnung / Factur-X), no signatures, no
 * encryption, no incremental updates. Anything else is born-digital or special and stays untouched – read() then
 * throws with a short reason.
 */
final class ScanPdf
{
    public const int MAX_PAGES = 500;

    /**
     * Keys that mark a PDF as more than a scan, found anywhere in the file or its object streams. Fonts mean text; the
     * rest are forms, signatures, attachments, scripts and e-invoice metadata.
     */
    private const string FORBIDDEN = '~/(?:Font|FontFile[23]?|AcroForm|EmbeddedFiles|EF|AF|ByteRange|Sig|Perms|JavaScript|JS|Encrypt|XFA)(?![A-Za-z0-9])|zugferd|factur-?x|xrechnung~i';

    /** Page keys that are fine to drop or that the rebuild keeps (boxes, rotation). */
    private const array PAGE_KEYS = ['/Type', '/Parent', '/Resources', '/MediaBox', '/CropBox', '/BleedBox', '/TrimBox', '/ArtBox', '/Rotate', '/Contents', '/Group', '/Thumb', '/StructParents', '/Tabs', '/Annots', '/LastModified', '/PieceInfo', '/ID'];

    /** @param  list<ScanPage>  $pages */
    private function __construct(public readonly array $pages) {}

    /**
     * The pages of a scan; throws (with a short reason) for every PDF that isn't one.
     */
    public static function read(string $bytes): self
    {
        $file = PdfFile::open($bytes);
        if (preg_match(self::FORBIDDEN, $file->searchable(), $m) === 1) {
            throw new RuntimeException('contains '.$m[0]);
        }
        $catalog = $file->dict(new PdfRef($file->rootRef()));
        $pages = [];
        self::walk($file, $catalog['/Pages'] ?? null, ['/Resources' => null, '/MediaBox' => null, '/CropBox' => null, '/Rotate' => 0], $pages, 0);
        if ($pages === []) {
            throw new RuntimeException('no pages');
        }

        return new self($pages);
    }

    /** How many pages a scan has (for the threshold per page); null when it isn't one. */
    public static function pageCount(string $bytes): ?int
    {
        try {
            return count(self::read($bytes)->pages);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $inherited
     * @param  list<ScanPage>  $pages
     */
    private static function walk(PdfFile $file, mixed $node, array $inherited, array &$pages, int $depth): void
    {
        if ($depth > 32) {
            throw new RuntimeException('page tree too deep');
        }
        $dict = $file->dict($node);
        foreach (['/Resources', '/MediaBox', '/CropBox', '/Rotate'] as $key) {
            if (array_key_exists($key, $dict)) {
                $inherited[$key] = $dict[$key];
            }
        }
        if (($dict['/Type'] ?? null) === '/Pages') {
            $kids = $file->resolve($dict['/Kids'] ?? null);
            if (! is_array($kids) || ! array_is_list($kids)) {
                throw new RuntimeException('kids');
            }
            foreach ($kids as $kid) {
                self::walk($file, $kid, $inherited, $pages, $depth + 1);
            }

            return;
        }
        if (count($pages) >= self::MAX_PAGES) {
            throw new RuntimeException('too many pages');
        }
        $pages[] = self::page($file, $dict, $inherited);
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $inherited
     */
    private static function page(PdfFile $file, array $page, array $inherited): ScanPage
    {
        $unknown = array_diff(array_keys($page), self::PAGE_KEYS);
        if ($unknown !== []) {
            throw new RuntimeException('page key '.reset($unknown));
        }
        $annots = $file->resolve($page['/Annots'] ?? []);
        if ($annots !== [] && $annots !== null) {
            throw new RuntimeException('annotations');
        }
        $resources = $file->dict($inherited['/Resources']);
        if (array_diff(array_keys($resources), ['/ProcSet', '/XObject']) !== []) {
            throw new RuntimeException('resources');
        }
        $xobjects = $file->dict($resources['/XObject'] ?? null);
        if (count($xobjects) !== 1 || ! (reset($xobjects) instanceof PdfRef)) {
            throw new RuntimeException('not one picture');
        }
        $name = (string) array_key_first($xobjects);
        [$width, $height, $jpeg] = self::picture($file, reset($xobjects));
        $content = self::content($file, $page['/Contents'] ?? null, $name);
        $box = self::box($file, $inherited['/MediaBox']) ?? throw new RuntimeException('media box');
        $rotate = $file->resolve($inherited['/Rotate']);

        return new ScanPage($box, self::box($file, $inherited['/CropBox']), is_int($rotate) ? (($rotate % 360) + 360) % 360 : 0, $content, substr($name, 1), $jpeg, $width, $height);
    }

    /** @return array{int, int, string} */
    private static function picture(PdfFile $file, PdfRef $ref): array
    {
        [$dict, $bytes] = $file->stream($ref);
        $filter = $file->resolve($dict['/Filter'] ?? null);
        $filter = is_array($filter) && count($filter) === 1 ? $file->resolve($filter[0]) : $filter;
        $space = $file->resolve($dict['/ColorSpace'] ?? null);
        if (($dict['/Subtype'] ?? null) !== '/Image' || $filter !== '/DCTDecode') {
            throw new RuntimeException('picture is no jpeg');
        }
        foreach (['/SMask', '/Mask', '/Decode', '/DecodeParms', '/ImageMask', '/Alternates', '/OC'] as $key) {
            if (isset($dict[$key])) {
                throw new RuntimeException('picture '.$key);
            }
        }
        if ($file->resolve($dict['/BitsPerComponent'] ?? 8) !== 8 || ! self::plainSpace($file, $space)) {
            throw new RuntimeException('picture colours');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG || ! in_array($info['channels'] ?? 3, [1, 3], true)) {
            throw new RuntimeException('picture unreadable');
        }

        return [$info[0], $info[1], $bytes];
    }

    /** DeviceGray, DeviceRGB, or an ICC profile with 1 or 3 components. */
    private static function plainSpace(PdfFile $file, mixed $space): bool
    {
        if (in_array($space, ['/DeviceGray', '/DeviceRGB'], true)) {
            return true;
        }
        if (is_array($space) && array_is_list($space) && count($space) === 2 && $file->resolve($space[0]) === '/ICCBased' && $space[1] instanceof PdfRef) {
            [$profile] = $file->stream($space[1]);

            return in_array($file->resolve($profile['/N'] ?? null), [1, 3], true);
        }

        return false;
    }

    /** The page's drawing: small, only the picture drawn once, no text operators. */
    private static function content(PdfFile $file, mixed $contents, string $name): string
    {
        $refs = $contents instanceof PdfRef ? [$contents] : $file->resolve($contents);
        if (! is_array($refs) || $refs === [] || ! array_is_list($refs)) {
            throw new RuntimeException('contents');
        }
        $content = '';
        foreach ($refs as $ref) {
            if (! $ref instanceof PdfRef) {
                throw new RuntimeException('contents');
            }
            $content .= $file->decoded($ref)."\n";
        }
        $draws = preg_match_all('/(\/[^\s\/\[\]()<>{}%]+)[\s]+Do\b/', $content, $m);
        if (strlen($content) > 8192 || $draws !== 1 || $m[1][0] !== $name || preg_match('/\b(?:BT|BI|sh|Tj|TJ)\b/', $content) === 1) {
            throw new RuntimeException('drawing');
        }

        return $content;
    }

    /** @return array{float, float, float, float}|null */
    private static function box(PdfFile $file, mixed $value): ?array
    {
        $box = $file->resolve($value);
        if (! is_array($box) || count($box) !== 4) {
            return null;
        }
        $numbers = array_map(static fn (mixed $n): float => is_int($n) || is_float($n) ? (float) $n : throw new RuntimeException('box'), array_map($file->resolve(...), array_values($box)));
        if (abs($numbers[2] - $numbers[0]) < 1 || abs($numbers[3] - $numbers[1]) < 1) {
            throw new RuntimeException('box');
        }

        return [$numbers[0], $numbers[1], $numbers[2], $numbers[3]];
    }
}
