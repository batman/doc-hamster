<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A label on receipts, one name per organisation (#32).
 *
 * @property string $id
 * @property string $org_id
 * @property string $name
 */
final class Tag extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'name'];

    /**
     * Skips the model events: a new organisation gets its rows whichever
     * organisation is active right now.
     */
    public static function addDefaults(Organization $organization): void
    {
        self::withoutEvents(static function () use ($organization): void {
            foreach (['invoice', 'receipt', 'contract'] as $key) {
                self::query()->create(['org_id' => $organization->id, 'name' => __('tags.defaults.'.$key)]);
            }
        });
    }

    /**
     * @return BelongsToMany<Document, $this>
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_tags');
    }
}
