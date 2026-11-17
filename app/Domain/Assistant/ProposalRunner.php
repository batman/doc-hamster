<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Audit\AuditLog;
use App\Domain\Banking\Links;
use App\Domain\Banking\Transaction;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Optimize\OptimizeQueue;
use App\Domain\Optimize\OptimizeSettings;
use App\Domain\Org\CurrentOrg;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertyValues;
use App\Jobs\Local\OptimizeDocument;
use RuntimeException;

/**
 * Takes over an assistant's proposal (D-040) as the person who clicked "Übernehmen" – with the same steps the app's
 * own buttons take, and an audit entry each. Deleting only ever moves into the Papierkorb. A proposal whose
 * documents or bookings are gone (or that no longer fits) fails with the reason instead.
 */
final class ProposalRunner
{
    public function __construct(
        private readonly CurrentOrg $org,
        private readonly AuditLog $audit,
        private readonly Links $links,
        private readonly PropertyValues $values,
    ) {}

    /** @return string what happened, for the toast and the tile */
    public function accept(Proposal $proposal, bool $swap = false, ?string $ip = null): string
    {
        $p = $proposal->payload;
        $user = $this->org->membership()->user_id;
        $meta = static fn (array $more = []): array => ['via' => 'assistant', 'proposal' => $proposal->id, ...$more];

        return match ($proposal->kind) {
            Proposal::LINK => (function () use ($p, $user, $meta, $ip): string {
                $transaction = Transaction::query()->find($p['transaction_id'] ?? '') ?? throw new RuntimeException(__('proposals.gone_booking'));
                $document = $this->document($p['document_id'] ?? '');
                if (! Document::query()->matchable()->whereKey($document->id)->exists()) {
                    throw new RuntimeException(__('proposals.not_matchable'));
                }
                $this->links->link($transaction, $document, $user);
                $this->audit->record('bank.matched', orgId: $this->org->id(), userId: $user, subject: $transaction, meta: $meta(['document' => $document->id]), ip: $ip);

                return __('proposals.done.link');
            })(),
            Proposal::MOVE => (function () use ($p, $user, $meta, $ip): string {
                $document = $this->document($p['document_id'] ?? '');
                $folder = $p['folder_id'] ?? '';
                if (! in_array($folder, Folder::targetIds(), true)) {
                    throw new RuntimeException(__('proposals.gone_folder'));
                }
                $document->update(['folder_id' => $folder]);
                $this->audit->record('document.moved', orgId: $this->org->id(), userId: $user, subject: $document, meta: $meta(['to' => $folder]), ip: $ip);

                return __('proposals.done.move', ['name' => (string) Folder::query()->whereKey($folder)->value('name')]);
            })(),
            Proposal::DUPLICATE, Proposal::DELETE => (function () use ($proposal, $p, $swap, $user, $meta, $ip): string {
                $id = $proposal->kind === Proposal::DELETE ? ($p['document_id'] ?? '') : ($swap ? ($p['keep_id'] ?? '') : ($p['remove_id'] ?? ''));
                $document = $this->document($id);
                $document->delete();
                $this->audit->record('document.trashed', orgId: $this->org->id(), userId: $user, subject: $document, meta: $meta(), ip: $ip);

                return __('proposals.done.trash', ['name' => $document->title, 'trash' => Folder::trashName($this->org->organization())]);
            })(),
            Proposal::SHRINK => (function () use ($p): string {
                $document = $this->document($p['document_id'] ?? '');
                $files = $document->files()->whereIn('mime', OptimizeQueue::MIMES)->whereNull('optimize')->get();
                if ($files->isEmpty()) {
                    throw new RuntimeException(__('proposals.nothing_to_shrink'));
                }
                foreach ($files as $file) {
                    $file->forceFill(['optimize' => OptimizeQueue::QUEUED, 'optimize_at' => now()])->save();
                    OptimizeDocument::dispatch($file->id, false, true);
                }

                return __('proposals.done.shrink');
            })(),
            Proposal::CORRECT => (function () use ($p, $user, $meta, $ip): string {
                $document = $this->document($p['document_id'] ?? '');
                $property = Property::query()->where('key', $p['property'] ?? '')->orWhere('name', $p['property'] ?? '')->first()
                    ?? throw new RuntimeException(__('proposals.gone_property'));
                try {
                    $value = $this->values->set($document, $property, (string) ($p['value'] ?? ''));
                } catch (\Throwable) {
                    throw new RuntimeException(__('proposals.bad_value'));
                }
                $this->audit->record('document.changed', orgId: $this->org->id(), userId: $user, subject: $document, meta: $meta(['property' => $property->name]), ip: $ip);

                return __('proposals.done.correct', ['name' => $property->name, 'value' => $this->values->display($value)]);
            })(),
            Proposal::SETTING => (function () use ($p): string {
                if (($p['setting'] ?? '') !== 'optimize_on') {
                    throw new RuntimeException(__('proposals.gone_setting'));
                }
                OptimizeSettings::save($this->org->organization(), ['on' => true]);
                OptimizeQueue::fill($this->org->organization());

                return __('proposals.done.setting_optimize');
            })(),
            default => throw new RuntimeException(__('proposals.unknown')),
        };
    }

    private function document(string $id): Document
    {
        return Document::query()->find($id) ?? throw new RuntimeException(__('proposals.gone_document'));
    }
}
