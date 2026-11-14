<?php

declare(strict_types=1);

namespace App\Jobs\Local;

use App\Domain\Documents\Document;
use App\Domain\Documents\ReceiptDate;
use App\Domain\Log\LogEntry;
use App\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Point 214: once after the update, every document gets its Belegdatum from its stored invoice date (confirmed, else
 * the plausible suggestion) or the fallbacks (EXIF, PDF, upload day). Dispatched by the migration that adds
 * doc_date_source; the cron worker runs it. One logbook entry ("Allgemein") per organisation with the count.
 */
final class BackfillReceiptDates implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct()
    {
        $this->onQueue(QueueName::LocalLow->value);
    }

    /** @return array<string, int> documents set per organisation */
    public function handle(ReceiptDate $dates): array
    {
        $counts = [];
        Document::query()->withoutGlobalScopes()
            ->chunkById(200, static function (Collection $documents) use ($dates, &$counts): void {
                foreach ($documents as $document) {
                    if ($dates->sync($document)) {
                        $counts[$document->org_id] = ($counts[$document->org_id] ?? 0) + 1;
                    }
                }
            });
        foreach ($counts as $orgId => $count) {
            LogEntry::write('general', 'event', trans_choice('receipt_date.backfilled', $count, ['count' => $count]), 'document.dates_set', (string) $orgId);
        }

        return $counts;
    }
}
