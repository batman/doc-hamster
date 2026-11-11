<?php

declare(strict_types=1);

namespace App\Jobs\Local;

use App\Domain\Documents\Document;
use App\Infrastructure\Environment\LocalOcr;
use App\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** The settings button "Texterkennung einrichten" (point 18): downloads run in the cron worker, not the request. */
final class SetUpOcr implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue(QueueName::Local->value);
    }

    public function handle(LocalOcr $ocr): void
    {
        if (($ocr->setUp()['state'] ?? null) === 'ok') {
            self::queueWaiting();
        }
    }

    /** Receipts that waited for OCR get their turn now (one job each, run one at a time). */
    public static function queueWaiting(): void
    {
        Document::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where(static fn ($w) => $w->whereNull('ocr_status')->orWhereIn('ocr_status', ['pending', 'unavailable']))
            ->select('id')->eachById(static fn (Document $d) => RecognizeText::dispatch($d->id));
    }
}
