<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Org\OrgScope;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertyValues;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Point 214: the Belegdatum (documents.doc_date) – one source of truth, the property Rechnungsdatum (invoice_date).
 * The confirmed value wins; else the recognised suggestion, unless it lies in the future or more than ten years back
 * (A381: then it stays a suggestion with a hint). Without one: the photo's capture date (EXIF), the PDF's creation
 * date, the upload day. `doc_date_source` says which (confirmed|recognised|exif|pdf|upload); "upload" means the
 * document has no Belegdatum of its own (the UI shows "Belegdatum fehlt").
 */
final class ReceiptDate
{
    public const CONFIRMED = 'confirmed';

    public const RECOGNISED = 'recognised';

    public const EXIF = 'exif';

    public const PDF = 'pdf';

    public const UPLOAD = 'upload';

    /** A381: a recognised date further back than this is not taken by itself. */
    public const MAX_YEARS_BACK = 10;

    public function __construct(private readonly PropertyValues $values) {}

    /** A381: not in the future and at most ten years back. */
    public static function plausible(string $ymd, ?CarbonImmutable $today = null): bool
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $date = CarbonImmutable::parse($ymd)->startOfDay();

        return $date->lessThanOrEqualTo($today) && $date->greaterThanOrEqualTo($today->subYears(self::MAX_YEARS_BACK));
    }

    /**
     * What the invoice date gives: a confirmed value always, a suggestion only when plausible.
     *
     * @return array{string, string}|null [Y-m-d, source]
     */
    public static function fromProperty(?string $ymd, ?string $state, ?CarbonImmutable $today = null): ?array
    {
        if ($ymd === null) {
            return null;
        }
        if ($state === DocumentProperty::CONFIRMED) {
            return [$ymd, self::CONFIRMED];
        }

        return self::plausible($ymd, $today) ? [$ymd, self::RECOGNISED] : null;
    }

    /**
     * The Belegdatum and its source as it should be now.
     *
     * @return array{string, string}
     */
    public function resolve(Document $document): array
    {
        $value = $this->values->get($document, 'invoice_date');
        $picked = self::fromProperty($value?->value_date?->toDateString(), $value?->state);
        if ($picked !== null) {
            return $picked;
        }
        $file = DocumentFile::query()->withoutGlobalScope(OrgScope::class)->where('document_id', $document->id)->orderBy('page_no')->orderBy('id')->first();
        if ($file !== null) {
            try {
                $found = ReceiptDateFile::read(Storage::disk('local')->path($file->path), $file->mime);
            } catch (Throwable) {
                $found = null;
            }
            if ($found !== null && self::plausible($found[0])) {
                return $found;
            }
        }

        return [($document->created_at ?? CarbonImmutable::now())->toDateString(), self::UPLOAD];
    }

    /** Writes the Belegdatum when it changed (without touching updated_at); true when it did. */
    public function sync(Document $document): bool
    {
        [$date, $source] = $this->resolve($document);
        if ($document->doc_date?->toDateString() === $date && $document->doc_date_source === $source) {
            return false;
        }
        DB::table('documents')->where('id', $document->id)->update(['doc_date' => $date, 'doc_date_source' => $source]);
        $document->forceFill(['doc_date' => $date, 'doc_date_source' => $source])->syncOriginalAttributes(['doc_date', 'doc_date_source']);

        return true;
    }

    public function syncId(string $documentId): void
    {
        $document = Document::query()->withoutGlobalScopes()->find($documentId);
        if ($document instanceof Document) {
            $this->sync($document);
        }
    }

    /** A381: the recognised invoice date that was not taken (future or too old), for the hint under the Belegdatum. */
    public function doubtful(Document $document): ?CarbonImmutable
    {
        $value = $this->values->get($document, 'invoice_date');
        if ($value === null || ! $value->isSuggested() || $value->value_date === null) {
            return null;
        }
        $ymd = $value->value_date->toDateString();

        return self::plausible($ymd) ? null : CarbonImmutable::parse($ymd);
    }

    /** Whether the document has no date of its own (only the upload day). */
    public static function missing(Document $document): bool
    {
        return $document->doc_date_source === null || $document->doc_date_source === self::UPLOAD;
    }

    /** Keeps doc_date in step with every change of an invoice date (set, suggested, confirmed, removed). */
    public static function watch(): void
    {
        $sync = static function (DocumentProperty $value): void {
            try {
                $key = Property::query()->withoutGlobalScope(OrgScope::class)->whereKey($value->property_id)->value('key');
                if ($key === 'invoice_date') {
                    app(self::class)->syncId($value->document_id);
                }
            } catch (Throwable $e) {
                report($e);
            }
        };
        DocumentProperty::saved($sync);
        DocumentProperty::deleted($sync);
    }
}
