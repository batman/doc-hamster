<?php

declare(strict_types=1);

namespace App\Domain\Optimize;

use App\Domain\Optimize\Pdf\ScanPdf;
use App\Domain\ReceiptPack\Pdf\PdfWriter;
use RuntimeException;
use Throwable;

/**
 * Point 236: shrinks one file into a new one, if it qualifies – a photo or a scan PDF (ScanPdf) whose average size per
 * page is over the threshold. The original is never changed here. The new file counts only when it is at least 30 %
 * smaller (A406); otherwise it is deleted and the result says "skip".
 */
final class FileShrinker
{
    /** Formats GD reads here (HEIC it can't). */
    private const array PHOTOS = ['image/jpeg', 'image/png', 'image/webp'];

    public static function shrink(string $path, string $mime, int $bytesPerPage, string $target): ShrinkResult
    {
        $size = (int) @filesize($path);
        if ($size <= 0) {
            return ShrinkResult::skip('file missing');
        }
        try {
            if ($mime === 'application/pdf') {
                $scan = ScanPdf::read((string) file_get_contents($path));
                if (! SizeRule::overThreshold($size, count($scan->pages), $bytesPerPage)) {
                    return ShrinkResult::small(count($scan->pages));
                }
                self::rebuild($scan, $target);
                $result = ['application/pdf', 'pdf', count($scan->pages)];
            } elseif (in_array($mime, self::PHOTOS, true)) {
                if (! SizeRule::overThreshold($size, 1, $bytesPerPage)) {
                    return ShrinkResult::small(1);
                }
                if (file_put_contents($target, ImageShrinker::photo($path, $mime)) === false) {
                    throw new RuntimeException('write failed');
                }
                $result = ['image/jpeg', 'jpg', 1];
            } else {
                return ShrinkResult::skip('type '.$mime);
            }
        } catch (Throwable $e) {
            @unlink($target);

            return ShrinkResult::skip(mb_substr($e->getMessage(), 0, 120));
        }
        clearstatcache(true, $target);
        $after = (int) @filesize($target);
        if (! SizeRule::worthKeeping($size, $after)) {
            @unlink($target);

            return ShrinkResult::skip('not smaller', $result[2], $size, $after);
        }

        return ShrinkResult::done($result[0], $result[1], $result[2], $size, $after);
    }

    /** Writes the scan again: every page in its order and size, its picture scaled and re-encoded where that is smaller. */
    private static function rebuild(ScanPdf $scan, string $target): void
    {
        $writer = new PdfWriter($target);
        foreach ($scan->pages as $page) {
            [$tw, $th] = SizeRule::target($page->width, $page->height, $page->widthPt(), $page->heightPt());
            if (! ImageShrinker::fits($page->width, $page->height, $tw * $th)) {
                throw new RuntimeException('page too big');
            }
            $image = @imagecreatefromstring($page->jpeg);
            if ($image === false) {
                throw new RuntimeException('page picture unreadable');
            }
            $jpeg = ImageShrinker::encode($image, [$tw, $th]);
            unset($image);
            // A page that is small already keeps its picture as it is.
            $writer->scanPage($writer->jpeg(strlen($jpeg) < strlen($page->jpeg) ? $jpeg : $page->jpeg), $page->imageName, $page->content, $page->mediaBox, $page->cropBox, $page->rotate);
        }
        $writer->finish();
    }
}
