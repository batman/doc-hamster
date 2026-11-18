<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Folder;
use App\Domain\Optimize\OriginalKeeper;
use App\Domain\Org\OrgScope;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use App\Events\TextRecognized;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A49, A50: docHamster itself notices a document that is there twice and files a duplicate proposal under Vorschläge
 * (like an assistant, nothing happens before the person's click). The same file (sha256) is sure; the same invoice
 * number with the same amount is likely – a second scan or photo of the same paper (A56: the better picture is the
 * one proposed to stay, the links move to it). Runs after the recognition; a failure never fails it.
 */
final class DuplicateFinder
{
    public const NAME = 'docHamster';

    public function handle(TextRecognized $event): void
    {
        if (OriginalKeeper::isOriginal($event->documentId)) {
            return;
        }
        try {
            $this->check($event->documentId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function check(string $documentId): void
    {
        $document = Document::query()->withoutGlobalScope(OrgScope::class)->whereKey($documentId)->first();
        if ($document === null) {
            return;
        }
        $org = $document->org_id;
        // A94: private folders (A69) take no part – neither the new document nor its twin.
        $private = Folder::privateBranch($org);
        if ($document->folder_id !== null && in_array($document->folder_id, $private, true)) {
            return;
        }
        $open = static fn () => Document::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $org)
            ->where(static fn ($q) => $q->whereNull('folder_id')->orWhereNotIn('folder_id', $private))->select('id');
        $hashes = DocumentFile::query()->withoutGlobalScope(OrgScope::class)->where('document_id', $document->id)->pluck('sha256')->filter()->all();
        if ($hashes !== []) {
            $same = DocumentFile::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $org)->whereIn('sha256', $hashes)
                ->where('document_id', '!=', $document->id)
                ->whereIn('document_id', $open())
                ->value('document_id');
            if (is_string($same)) {
                // The same file: the one that was there first stays.
                Proposal::file(Proposal::DUPLICATE, ['keep_id' => $same, 'remove_id' => $document->id], (string) __('proposals.found.same_file'), self::NAME, true, $org);

                return;
            }
        }
        $twin = $this->sameInvoice($document);
        if ($twin !== null && ($twin->folder_id === null || ! in_array($twin->folder_id, $private, true))) {
            [$keep, $remove] = self::quality($twin) >= self::quality($document) ? [$twin, $document] : [$document, $twin];
            Proposal::file(Proposal::DUPLICATE, ['keep_id' => $keep->id, 'remove_id' => $remove->id], (string) __('proposals.found.same_invoice'), self::NAME, false, $org);
        }
    }

    /** Another document with the same invoice number (letters and digits only, at least 4) and the same amount. */
    private function sameInvoice(Document $document): ?Document
    {
        $keys = Property::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $document->org_id)->whereIn('key', ['invoice_number', 'invoice_amount'])->pluck('id', 'key');
        if (! isset($keys['invoice_number'], $keys['invoice_amount'])) {
            return null;
        }
        $values = DocumentProperty::query()->withoutGlobalScope(OrgScope::class)->where('document_id', $document->id)->get()->keyBy('property_id');
        $number = self::compact((string) $values->get($keys['invoice_number'])?->value_text);
        $amount = $values->get($keys['invoice_amount'])?->value_int;
        if (strlen($number) < 4 || $amount === null) {
            return null;
        }
        $sameAmount = DocumentProperty::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $document->org_id)
            ->where('property_id', $keys['invoice_amount'])->where('value_int', $amount)->where('document_id', '!=', $document->id)->pluck('document_id');
        if ($sameAmount->isEmpty()) {
            return null;
        }
        $candidates = DocumentProperty::query()->withoutGlobalScope(OrgScope::class)->where('property_id', $keys['invoice_number'])
            ->whereIn('document_id', $sameAmount)->get();
        foreach ($candidates as $candidate) {
            if (self::compact((string) $candidate->value_text) === $number) {
                $twin = Document::query()->withoutGlobalScope(OrgScope::class)->whereKey($candidate->document_id)->first();
                if ($twin !== null) {
                    return $twin;
                }
            }
        }

        return null;
    }

    /** A56: how good the picture is – pixels of a photo, bytes per page of a PDF (a scan with more detail is larger). */
    public static function quality(Document $document): int
    {
        $best = 0;
        foreach (DocumentFile::query()->withoutGlobalScope(OrgScope::class)->where('document_id', $document->id)->get() as $file) {
            $score = (int) $file->size;
            if (str_starts_with($file->mime, 'image/')) {
                $size = @getimagesize(Storage::disk('local')->path($file->path));
                $score = is_array($size) ? $size[0] * $size[1] : $score;
            }
            $best = max($best, $score);
        }

        return $best;
    }

    private static function compact(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text));
    }
}
