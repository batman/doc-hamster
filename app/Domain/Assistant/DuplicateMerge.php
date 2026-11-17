<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Properties\DocumentProperty;
use Illuminate\Support\Facades\DB;

/**
 * A56: two copies of the same paper – an old scan already linked, a new better one. Before the other copy goes into
 * the Papierkorb, what people did with it moves to the kept one: its links to bookings, its confirmed values (where
 * the kept one has none or only a suggestion), its tags, its note and – when the kept one sits in no folder – its
 * folder. Nothing a person decided gets lost by keeping the better picture.
 */
final class DuplicateMerge
{
    /** @return int how many links moved */
    public static function into(Document $keep, Document $remove): int
    {
        return DB::transaction(static function () use ($keep, $remove): int {
            $moved = 0;
            foreach (TransactionMatch::query()->where('document_id', $remove->id)->get() as $match) {
                $twin = TransactionMatch::query()->where('document_id', $keep->id)->where('transaction_id', $match->transaction_id)->first();
                if ($twin === null) {
                    $match->forceFill(['document_id' => $keep->id])->save();
                    $moved++;
                } elseif ($twin->state !== TransactionMatch::CONFIRMED && $match->state === TransactionMatch::CONFIRMED) {
                    $twin->forceFill(['state' => TransactionMatch::CONFIRMED, 'decided_by' => $match->decided_by])->save();
                    $match->delete();
                } else {
                    $match->delete();
                }
            }
            $kept = DocumentProperty::query()->where('document_id', $keep->id)->get()->keyBy('property_id');
            foreach (DocumentProperty::query()->where('document_id', $remove->id)->where('state', DocumentProperty::CONFIRMED)->get() as $value) {
                $there = $kept->get($value->property_id);
                if ($there !== null && $there->state === DocumentProperty::CONFIRMED) {
                    continue;
                }
                $there?->delete();
                $value->replicate()->forceFill(['document_id' => $keep->id])->save();
            }
            $keep->tags()->syncWithoutDetaching($remove->tags()->pluck('tags.id')->all());
            $note = trim(implode("\n\n", array_unique(array_filter([trim((string) $keep->note), trim((string) $remove->note)]))));
            $keep->forceFill([
                'note' => $note === '' ? null : $note,
                'folder_id' => $keep->folder_id ?? $remove->folder_id,
            ])->save();

            return $moved;
        });
    }
}
