<?php

declare(strict_types=1);

namespace App\Domain\Optimize;

/**
 * Point 236: the arithmetic of "Dokumentgröße optimieren" – which files are big enough to look at, how many pixels a
 * page keeps (300 dpi of its page size; a photo like an A4 page), the JPEG quality and when a result is worth keeping
 * (at least 30 % smaller, A406).
 */
final class SizeRule
{
    public const int DPI = 300;

    public const int QUALITY = 80;

    /** The result must be at most this share of the original (30 % smaller). */
    public const float KEEP_SHARE = 0.7;

    /** A4 in PDF points (1/72 inch): a photo gets as many pixels as an A4 page at 300 dpi (2480 × 3508). */
    public const float A4_LONG_PT = 841.89;

    public const float A4_SHORT_PT = 595.28;

    /** Whether the average size per page is over the threshold. */
    public static function overThreshold(int $bytes, int $pages, int $bytesPerPage): bool
    {
        return $pages > 0 && $bytesPerPage > 0 && $bytes / $pages > $bytesPerPage;
    }

    /**
     * The pixel size an image keeps on a page of the given size (points): DPI over the page, never larger than it is,
     * the aspect ratio kept. Long edge goes with long edge, so it works whichever way the image lies on the page. Of
     * the two edges the more generous one counts – a long narrow till receipt keeps its width.
     *
     * @return array{int, int}
     */
    public static function target(int $width, int $height, float $pageWidthPt, float $pageHeightPt, int $dpi = self::DPI): array
    {
        if ($width <= 0 || $height <= 0 || $pageWidthPt <= 0 || $pageHeightPt <= 0) {
            return [max(0, $width), max(0, $height)];
        }
        $longRatio = max($pageWidthPt, $pageHeightPt) / 72 * $dpi / max($width, $height);
        $shortRatio = min($pageWidthPt, $pageHeightPt) / 72 * $dpi / min($width, $height);
        $scale = min(1.0, max($longRatio, $shortRatio));
        if ($scale >= 1.0) {
            return [$width, $height];
        }

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    /**
     * A photo has no page: it keeps the pixels of an A4 page at 300 dpi (long edge 3508 px).
     *
     * @return array{int, int}
     */
    public static function photoTarget(int $width, int $height): array
    {
        return self::target($width, $height, self::A4_SHORT_PT, self::A4_LONG_PT);
    }

    /** A406: the optimised file is kept only when it is at least 30 % smaller. */
    public static function worthKeeping(int $before, int $after): bool
    {
        return $before > 0 && $after > 0 && $after <= $before * self::KEEP_SHARE;
    }
}
