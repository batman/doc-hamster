<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Audit\AuditLog;
use App\Domain\Banking\Links;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Optimize\OptimizeQueue;
use App\Domain\Optimize\OptimizeSettings;
use App\Domain\Optimize\OriginalKeeper;
use App\Domain\Optimize\ShrinkResult;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertyValues;
use App\Jobs\Local\OptimizeDocument;
use App\Jobs\Local\RecognizeText;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Takes over an assistant's proposal (D-040) as the person who clicked "Übernehmen" – with the same steps the app's
 * own buttons take, the same rights (A57: linking needs "Bank", tidying "Bearbeiten", duplicates "Löschen", settings the
 * admin; only documents they may see), and an audit entry each. Deleting only ever moves into the Papierkorb. A proposal whose
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
    /** A57: the right a person needs to take over each kind – the same as doing it by hand. */
    private const NEEDS = [
        Proposal::LINK => Permission::ImportBanking, Proposal::UNLINK => Permission::ImportBanking,
        Proposal::MOVE => Permission::EditDocuments, Proposal::TITLE => Permission::EditDocuments, Proposal::CORRECT => Permission::EditDocuments,
        Proposal::SHRINK => Permission::EditDocuments, Proposal::STRAIGHTEN => Permission::EditDocuments,
        Proposal::DUPLICATE => Permission::TrashDocuments, Proposal::DELETE => Permission::TrashDocuments,
        Proposal::SETTING => Permission::ManageSettings,
    ];

    public function accept(Proposal $proposal, bool $swap = false, ?string $ip = null): string
    {
        // Point 33: the folder tree has its own rules and rights (FolderProposals).
        if (str_starts_with($proposal->kind, 'folder_')) {
            return app(FolderProposals::class)->accept($proposal, $ip);
        }
        $p = $proposal->payload;
        $user = $this->org->membership()->user_id;
        $meta = static fn (array $more = []): array => ['via' => 'assistant', 'proposal' => $proposal->id, ...$more];
        // A57: the person who clicks takes it over with their own rights – no more than they could do by hand.
        $needed = self::NEEDS[$proposal->kind] ?? null;
        if ($needed === null || ! Gate::allows($needed->value)) {
            throw new RuntimeException(__('proposals.not_allowed'));
        }

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
            Proposal::UNLINK => (function () use ($p, $user, $meta, $ip): string {
                $this->document($p['document_id'] ?? '');
                $match = TransactionMatch::query()->where('transaction_id', $p['transaction_id'] ?? '')->where('document_id', $p['document_id'] ?? '')->first()
                    ?? throw new RuntimeException(__('proposals.gone_link'));
                $transaction = $match->transaction;
                $this->links->unlink($match);
                $this->audit->record('bank.unmatched', orgId: $this->org->id(), userId: $user, subject: $transaction, meta: $meta(['document' => $p['document_id'] ?? '']), ip: $ip);

                return __('proposals.done.unlink');
            })(),
            Proposal::TITLE => (function () use ($p, $user, $meta, $ip): string {
                $document = $this->document($p['document_id'] ?? '');
                $title = mb_substr(trim((string) ($p['title'] ?? '')), 0, 255);
                if ($title === '') {
                    throw new RuntimeException(__('proposals.bad_value'));
                }
                $document->update(['title' => $title]);
                $this->audit->record('document.changed', orgId: $this->org->id(), userId: $user, subject: $document, meta: $meta(['title' => $title]), ip: $ip);

                return __('proposals.done.title', ['title' => $title]);
            })(),
            Proposal::DUPLICATE, Proposal::DELETE => (function () use ($proposal, $p, $swap, $user, $meta, $ip): string {
                $id = $proposal->kind === Proposal::DELETE ? ($p['document_id'] ?? '') : ($swap ? ($p['keep_id'] ?? '') : ($p['remove_id'] ?? ''));
                $document = $this->document($id);
                if ($proposal->kind === Proposal::DUPLICATE) {
                    // A56: links, confirmed values, tags, note and folder go to the copy that stays.
                    DuplicateMerge::into($this->document($swap ? ($p['remove_id'] ?? '') : ($p['keep_id'] ?? '')), $document);
                }
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
            Proposal::STRAIGHTEN => (function () use ($proposal, $p, $user, $meta, $ip): string {
                $document = $this->document($p['document_id'] ?? '');
                $file = $document->files()->whereIn('mime', Straightener::PHOTOS)->orderBy('page_no')->first()
                    ?? throw new RuntimeException(__('proposals.photo_only'));
                [$angle, $box] = $proposal->straightening();
                $made = Straightener::jpeg(Straightener::straighten($file, $angle, $box));
                $target = tempnam(sys_get_temp_dir(), 'dh-str');
                if ($target === false || file_put_contents($target, $made['jpeg']) === false) {
                    throw new RuntimeException(__('proposals.unreadable'));
                }
                OriginalKeeper::swap($document, $file, $target, ShrinkResult::done('image/jpeg', 'jpg', 1, (int) $file->size, strlen($made['jpeg'])), $this->org->organization());
                // The new picture is the page as it is: no old turn or cut on it; its text is read again.
                $file->forceFill(['transform' => null])->save();
                $document->forceFill(['ocr_status' => 'pending'])->save();
                RecognizeText::dispatch($document->id);
                $this->audit->record('document.changed', orgId: $this->org->id(), userId: $user, subject: $document, meta: $meta(['straightened' => $angle]), ip: $ip);

                return __('proposals.done.straighten');
            })(),
            Proposal::CORRECT => (function () use ($p, $user, $meta, $ip): string {
                $document = $this->document($p['document_id'] ?? '');
                $property = Property::query()->where('key', $p['property'] ?? '')->orWhere('name', $p['property'] ?? '')->first()
                    ?? throw new RuntimeException(__('proposals.gone_property'));
                if (trim((string) ($p['value'] ?? '')) === '') {
                    // A52: a value that does not belong there is taken away.
                    $document->properties()->where('property_id', $property->id)->delete();
                    $this->audit->record('document.changed', orgId: $this->org->id(), userId: $user, subject: $document, meta: $meta(['property' => $property->name, 'cleared' => true]), ip: $ip);

                    return __('proposals.done.clear', ['name' => $property->name]);
                }
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

    /** A57: only a document the clicking person may see (visibility and role restriction). */
    private function document(string $id): Document
    {
        return Document::query()->visibleTo($this->org->membership()->user_id, Gate::allows(Permission::ViewAllDocuments->value))->whereKey($id)->first() ?? throw new RuntimeException(__('proposals.gone_document'));
    }
}
