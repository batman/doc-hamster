<?php

declare(strict_types=1);

namespace App\Domain\Properties;

use App\Domain\Documents\Document;
use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A typed property of documents ("Eigenschaft", points 122.1/122.2): Rechnungsdatum, Rechnungsbetrag, IBAN …
 * One value per document (DocumentProperty). The default ones (with a `key`) can be switched off but not deleted;
 * other code finds them by key (GiroCode, suggestions). `tag_ids` limits a property to documents with one of
 * these tags (A246); null = every document.
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $key
 * @property string $name
 * @property string $type text|amount|date|iban|number|choice
 * @property list<string>|null $options the values of a choice
 * @property list<string>|null $tag_ids
 * @property int $sort
 * @property bool $active
 */
final class Property extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const TYPES = ['text', 'amount', 'date', 'iban', 'number', 'choice'];

    /** The properties every organisation starts with (key => type); names from lang properties.defaults. A249: paid_at. */
    public const DEFAULTS = [
        'invoice_date' => 'date',
        'invoice_amount' => 'amount',
        'invoice_number' => 'text',
        'sender' => 'text',
        'recipient' => 'text',
        'iban' => 'iban',
        'due_date' => 'date',
        'paid_at' => 'date',
    ];

    /** A symbol per type (rows in the settings, the popup "Markieren als"). */
    public const ICONS = [
        'text' => 'file-text',
        'amount' => 'currency-euro',
        'date' => 'calendar',
        'iban' => 'building-bank',
        'number' => 'list-numbers',
        'choice' => 'list',
    ];

    protected $fillable = ['org_id', 'key', 'name', 'type', 'options', 'tag_ids', 'sort', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['options' => 'array', 'tag_ids' => 'array', 'sort' => 'integer', 'active' => 'boolean'];
    }

    /**
     * @return HasMany<DocumentProperty, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(DocumentProperty::class);
    }

    public function isDefault(): bool
    {
        return $this->key !== null;
    }

    public function icon(): string
    {
        return self::ICONS[$this->type] ?? 'file-text';
    }

    /**
     * A246: whether a document with these tags may carry the property (no limit = every document).
     *
     * @param  iterable<string>  $tagIds
     */
    public function allowsTags(iterable $tagIds): bool
    {
        $limit = $this->tag_ids;
        if ($limit === null || $limit === []) {
            return true;
        }
        foreach ($tagIds as $id) {
            if (in_array($id, $limit, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The active organisation's properties in their order.
     *
     * @return Collection<int, Property>
     */
    public static function ordered(bool $activeOnly = false): Collection
    {
        return self::query()->when($activeOnly, static fn ($q) => $q->where('active', true))->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * The active properties a document may carry (A246), in their order; works without an active organisation.
     *
     * @return Collection<int, Property>
     */
    public static function forDocument(Document $document): Collection
    {
        $tagIds = $document->tags()->pluck('tags.id')->all();

        return self::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $document->org_id)->where('active', true)
            ->orderBy('sort')->orderBy('id')->get()
            ->filter(static fn (self $property): bool => $property->allowsTags($tagIds))->values();
    }

    /** Skips the model events: a new organisation gets its properties whichever organisation is active right now. */
    public static function addDefaults(Organization $organization): void
    {
        self::withoutEvents(static function () use ($organization): void {
            $sort = 0;
            foreach (self::DEFAULTS as $key => $type) {
                self::query()->withoutGlobalScope(OrgScope::class)->create([
                    'org_id' => $organization->id, 'key' => $key, 'name' => __('properties.defaults.'.$key), 'type' => $type, 'sort' => $sort++, 'active' => true,
                ]);
            }
        });
    }
}
