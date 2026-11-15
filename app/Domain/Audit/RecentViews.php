<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Documents\Document;
use App\Domain\ReceiptPack\ReceiptPack;
use Carbon\CarbonInterface;

/**
 * A380 "Zuletzt angesehen" (Personen page): what a person opened or downloaded last, read from the audit trail
 * (every document view and download is recorded there, point 215).
 */
final class RecentViews
{
    public const array ACTIONS = ['document.viewed', 'document.file_downloaded', 'pack.downloaded'];

    /**
     * @return list<array{action: string, title: string, at: CarbonInterface}>
     */
    public static function of(string $orgId, string $userId, int $limit = 5): array
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
