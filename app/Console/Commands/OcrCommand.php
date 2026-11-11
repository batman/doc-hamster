<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Documents\Document;
use App\Infrastructure\Environment\Addon;
use App\Infrastructure\Environment\Doctor;
use App\Jobs\Local\RecognizeText;
use Illuminate\Console\Command;

/**
 * Reads the text of receipts that have none yet, e.g. from before the OCR
 * add-on was installed. Runs directly, not via the queue. Runs again safely.
 */
final class OcrCommand extends Command
{
    protected $signature = 'documents:ocr {--all : Also read receipts that already have text}';

    protected $description = 'Recognise the text of receipts without text (OCR add-on)';

    public function handle(Doctor $doctor): int
    {
        if (! $doctor->isAvailable(Addon::Ocr)) {
            $this->error('OCR is not available. Install: tesseract-ocr, tesseract-ocr-deu, ocrmypdf.');

            return self::FAILURE;
        }

        $query = Document::query()->withoutGlobalScopes()->whereNull('deleted_at');
        if (! $this->option('all')) {
            $query->where(static fn ($w) => $w->whereNull('ocr_status')->orWhereIn('ocr_status', ['pending', 'unavailable']));
        }

        $done = 0;
        $query->select('id')->eachById(function (Document $document) use ($doctor, &$done): void {
            (new RecognizeText($document->id))->handle($doctor);
            $done++;
        });
        $this->info("{$done} receipts read.");

        return self::SUCCESS;
    }
}
