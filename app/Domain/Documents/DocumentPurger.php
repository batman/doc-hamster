<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Audit\AuditLog;
use App\Domain\Org\OrgScope;
use Illuminate\Support\Facades\Storage;

/**
 * Deleting documents for good (points 77, 207): the files on disk, the rows (their files, matches … cascade) and one
 * audit entry per document, written before it goes. Used by "Endgültig löschen" and by the daily Papierkorb job,
 * which runs without an active organisation – so the files are read without the org scope.
 */
final readonly class DocumentPurger
{
    public function __construct(private AuditLog $audit) {}

    /**
     * @param  iterable<Document>  $documents
     * @param  string|null  $reason  null = by hand; "undo_ended" / "kept_days" = the daily job
     */
    public function purge(iterable $documents, ?string $userId = null, ?string $ip = null, ?string $reason = null): int
    {
        $disk = Storage::disk('local');
        $count = 0;
        foreach ($documents as $document) {
            $paths = DocumentFile::query()->withoutGlobalScope(OrgScope::class)->where('document_id', $document->id)->get()
                ->flatMap(static fn (DocumentFile $file): array => array_values(array_filter([$file->path, $file->thumb_path])))->all();
            // Logged before it goes: the audit keeps the id and title, the rows cascade away with the document.
            $meta = ['title' => $document->title, 'files' => count($paths)];
            if ($reason !== null) {
                $meta['reason'] = $reason;
            }
            $this->audit->record('document.purged', orgId: $document->org_id, userId: $userId, subject: $document, meta: $meta, ip: $ip);
            $document->forceDelete();
            $disk->delete($paths);
            $count++;
        }

        return $count;
    }
}
