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
 * @property string $color #rrggbb, any colour (Q-29)
 */
final class Tag extends Model
{
    use BelongsToOrg;
    use HasUlids;

    /** The six colours of the design, offered first and given to new tags. */
    public const PALETTE = ['#ff7622', '#3fb68f', '#6f9cf2', '#a97df0', '#ee7aa6', '#d9a826'];

    protected $fillable = ['org_id', 'name', 'color'];

    /** Black or white, whichever reads better on the colour (selected pills are filled). */
    public function textColor(): string
    {
        [$r, $g, $b] = array_map(static fn (string $hex): float => hexdec($hex) / 255, str_split(substr($this->color, 1), 2));
        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return $luminance > 0.5 ? '#1a1a18' : '#ffffff';
    }

    /**
     * Skips the model events: a new organisation gets its rows whichever
     * organisation is active right now.
     */
    public static function addDefaults(Organization $organization): void
    {
        self::withoutEvents(static function () use ($organization): void {
            foreach (['invoice', 'receipt', 'contract'] as $key) {
                self::query()->create(['org_id' => $organization->id, 'name' => __('tags.defaults.'.$key), 'color' => self::PALETTE[array_search($key, ['invoice', 'receipt', 'contract'], true)]]);
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
