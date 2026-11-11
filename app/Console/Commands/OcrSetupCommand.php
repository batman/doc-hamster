<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Environment\LocalOcr;
use App\Jobs\Net\SetUpOcr;
use Illuminate\Console\Command;

/**
 * Sets up text recognition on a server without root (point 18, A17):
 * downloads a portable Tesseract into storage/app/ocr and test-runs it;
 * where that cannot work, fetches the browser fallback (A16) instead.
 */
final class OcrSetupCommand extends Command
{
    protected $signature = 'ocr:setup';

    protected $description = 'Download a portable Tesseract (or the browser OCR fallback) with pinned checksums';

    public function handle(LocalOcr $ocr): int
    {
        if ($ocr->systemAvailable()) {
            $this->info('System OCR (tesseract + ocrmypdf) is available – nothing to do.');

            return self::SUCCESS;
        }
        $ocr->markRunning();
        $status = $ocr->setUp(SetUpOcr::fetcher());
        if (($status['state'] ?? null) === 'ok') {
            SetUpOcr::queueWaiting();
            $this->info('App-owned Tesseract works; waiting receipts are queued.');

            return self::SUCCESS;
        }
        $this->warn('App-owned Tesseract does not work here: '.($status['reason'] ?? '?'));
        $browser = $status['browser'] ?? '?';
        $browser === 'ok' ? $this->info('Browser fallback installed.') : $this->error('Browser fallback failed: '.$browser);

        return $browser === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
