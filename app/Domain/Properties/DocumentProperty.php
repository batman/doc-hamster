<?php

declare(strict_types=1);

namespace App\Domain\Properties;

use App\Domain\Documents\Document;
use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The value of one property on one document (point 122.1). Stored by type: text, IBAN and choice in `value_text`,
 * amounts in minor units with `currency`, numbers ×10000 in `value_int`, dates in `value_date`. `state` suggested =
 * read by the app, not yet confirmed; a confirmed value is never overwritten by a suggestion (PropertyValues).
 * `page`/`box` say where on the document the value stands (relative 0..1, like the OCR words).
 *
 * @property string $id
 * @property string $org_id
 * @property string $document_id
 * @property string $property_id
 * @property string|null $value_text
 * @property int|null $value_int
 * @property Carbon|null $value_date
 * @property string|null $currency
 * @property string $state confirmed|suggested
 * @property int|null $page
 * @property array{x: float, y: float, w: float, h: float}|null $box
 * @property-read Property $property
 */
final class DocumentProperty extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const CONFIRMED = 'confirmed';

    public const SUGGESTED = 'suggested';

    protected $table = 'document_properties';

    protected $fillable = ['org_id', 'document_id', 'property_id', 'value_text', 'value_int', 'value_date', 'currency', 'state', 'page', 'box'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value_int' => 'integer', 'value_date' => 'date', 'page' => 'integer', 'box' => 'array'];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isSuggested(): bool
    {
        return $this->state === self::SUGGESTED;
    }
}
