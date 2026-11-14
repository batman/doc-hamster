<?php

declare(strict_types=1);

namespace App\Domain\Advisor;

use App\Domain\Audit\AuditLogEntry;
use App\Domain\Documents\Document;
use App\Domain\ReceiptPack\ReceiptPack;
use Carbon\CarbonInterface;

/**
 * A380 "Zuletzt angesehen": what the tax advisor opened or downloaded last, read from the audit trail (every view
 * and download of the advisor is recorded there, point 210.5).
 */
final class RecentViews
{
    /** The audit actions shown (list pages, "advisor.list_viewed", are recorded but not listed here). */
    public const array ACTIONS = ['advisor.document_viewed', 'advisor.file_downloaded', 'advisor.pack_downloaded'];

    /**
     * @return list<array{action: string, title: string, at: CarbonInterface}>
     */
    public static function of(string $orgId, string $userId, int $limit = 10): array
    {
        $entries = AuditLogEntry::query()->where('org_id', $orgId)->where('user_id', $userId)->whereIn('action', self::ACTIONS)
            ->latest()->orderByDesc('id')->limit($limit)->get();
        $documents = Document::query()->withTrashed()->whereIn('id', $entries->where('subject_type', 'documents')->pluck('subject_id')->filter()->all())->pluck('title', 'id');
        $packs = ReceiptPack::ofOrg($orgId)->whereIn('id', $entries->where('subject_type', 'receipt_packs')->pluck('subject_id')->filter()->all())->get()->keyBy('id');

        $rows = [];
        foreach ($entries as $entry) {
            $title = match ($entry->subject_type) {
                'documents' => (string) ($documents[$entry->subject_id] ?? '?'),
                'receipt_packs' => $packs->get((string) $entry->subject_id)?->fileName() ?? '?',
                default => (string) ($entry->meta_json['title'] ?? ''),
            };
            $rows[] = ['action' => $entry->action, 'title' => $title, 'at' => $entry->created_at];
        }

        return $rows;
    }
}
