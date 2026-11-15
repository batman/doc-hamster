<?php

declare(strict_types=1);

namespace App\Jobs\Local;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\ReceiptDate;
use App\Domain\Log\LogEntry;
use App\Domain\Optimize\FileShrinker;
use App\Domain\Optimize\OptimizeQueue;
use App\Domain\Optimize\OptimizeSettings;
use App\Domain\Optimize\OriginalKeeper;
use App\Domain\Optimize\ShrinkResult;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use App\Queue\QueueName;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Point 236 "Dokumentgröße optimieren": one file, on the local queue (no network, D-020), one at a time (pictures take
 * memory). A new upload comes on the normal queue with $ocrAfter – its text recognition starts when this is done, so
 * OCR reads the smaller file; the stock of older files comes on the low queue. Whatever happens, the file is marked as
 * looked at, so it is never tried again (A406).
 */
final class OptimizeDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $maxExceptions = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $fileId, public readonly bool $ocrAfter = false)
    {
        $this->onQueue(($ocrAfter ? QueueName::Local : QueueName::LocalLow)->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('optimize'))->releaseAfter(20)->expireAfter(700)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(ReceiptDate $dates): void
    {
        $file = DocumentFile::query()->withoutGlobalScope(OrgScope::class)->find($this->fileId);
        $document = $file === null ? null : Document::query()->withoutGlobalScopes()->find($file->document_id);
        try {
            if ($file !== null && $document !== null) {
                $this->optimize($file, $document, $dates);
            }
        } finally {
            // The text recognition waited for this file (RecognizeText skips a queued file): now it may read it.
            $status = $document?->fresh()?->getAttribute('ocr_status');
            if ($document !== null && ($this->ocrAfter || $status === null || $status === 'pending')) {
                RecognizeText::dispatch($document->id);
            }
        }
    }

    private function optimize(DocumentFile $file, Document $document, ReceiptDate $dates): void
    {
        $organization = Organization::query()->find($document->org_id);
        // Switched off meanwhile, in the Papierkorb, an original itself or done already: back to "not looked at".
        if ($organization === null || ! OptimizeSettings::enabled($organization) || $document->trashed() || $document->getAttribute('original_of') !== null
            || ! in_array($file->getAttribute('optimize'), [null, OptimizeQueue::QUEUED], true)) {
            if ($file->getAttribute('optimize') === OptimizeQueue::QUEUED) {
                $file->forceFill(['optimize' => null])->save();
            }

            return;
        }
        self::roomForPictures();
        // The Belegdatum may come from the photo's EXIF block: worked out before the file changes.
        if ($document->doc_date_source === null) {
            $dates->sync($document);
            $document->refresh();
        }
        $source = Storage::disk('local')->path($file->path);
        $target = tempnam(sys_get_temp_dir(), 'dh-opt');
        if ($target === false) {
            return;
        }
        try {
            $result = FileShrinker::shrink($source, $file->mime, OptimizeSettings::bytesPerPage($organization), $target);
            if ($result->status !== ShrinkResult::DONE) {
                $file->forceFill(['optimize' => $result->status, 'optimize_at' => now()])->save();

                return;
            }
            OriginalKeeper::swap($document, $file, $target, $result, $organization);
            LogEntry::write('general', 'event', (string) __('optimize.log.done', [
                'title' => $document->title, 'before' => self::mb($result->before), 'after' => self::mb($result->after),
            ]), 'document.optimized', $document->org_id, documentId: $document->id);
        } catch (Throwable $e) {
            report($e);
            $file->forceFill(['optimize' => ShrinkResult::SKIP, 'optimize_at' => now()])->save();
        } finally {
            @unlink($target);
        }
    }

    /** A scan page at 600 dpi is ~35 million pixels; GD needs a few bytes per pixel. */
    private static function roomForPictures(): void
    {
        $limit = (string) ini_get('memory_limit');
        if ($limit !== '-1' && (int) $limit > 0 && str_ends_with(strtoupper($limit), 'M') && (int) $limit < 1024) {
            @ini_set('memory_limit', '1024M');
        }
    }

    private static function mb(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 1, ',', '.');
    }
}
