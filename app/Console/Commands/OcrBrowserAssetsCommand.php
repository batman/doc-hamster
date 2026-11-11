<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Environment\LocalOcr;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fetches tesseract.js, its core and the deu/eng language data into
 * public/vendor/tesseract (git-ignored), pinned and SHA-256-checked, so the
 * browser fallback (point 18, A16) never loads from a CDN at runtime.
 */
final class OcrBrowserAssetsCommand extends Command
{
    protected $signature = 'ocr:browser-assets';

    protected $description = 'Download the self-hosted tesseract.js files for the browser OCR fallback';

    public function handle(LocalOcr $ocr): int
    {
        try {
            $ocr->installBrowser();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Browser OCR files are in '.$ocr->browserDir());

        return self::SUCCESS;
    }
}
