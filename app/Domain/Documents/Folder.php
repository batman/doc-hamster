<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder for receipts, nested up to MAX_DEPTH levels (issue #77).
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $parent_id
 * @property string $name
 * @property bool $is_default the folder "Belege": cannot be deleted or moved
 * @property bool $bank_matching whether the bank matching may use receipts of this folder
 */
final class Folder extends Model
{
    use BelongsToOrg;
    use HasUlids;

    /** Levels below the top: a deeper tree is hard to use on a phone. */
    public const MAX_DEPTH = 4;

    protected $fillable = ['org_id', 'parent_id', 'name', 'is_default', 'bank_matching'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'bank_matching' => 'boolean'];
    }

    /**
     * Skips the model events: a new organisation gets its folder whichever
     * organisation is active right now.
     */
    public static function addDefaults(Organization $organization): void
    {
        self::withoutEvents(static function () use ($organization): void {
            self::query()->create(['org_id' => $organization->id, 'name' => __('folders.default'), 'is_default' => true, 'bank_matching' => true]);
        });
    }

    /** The "Belege" folder of an organisation; made if an old organisation has none yet. */
    public static function defaultIdFor(string $orgId): string
    {
        $existing = self::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)->where('is_default', true)->value('id');
        if (is_string($existing)) {
            return $existing;
        }

        return self::withoutEvents(static fn (): string => self::query()->create(['org_id' => $orgId, 'name' => __('folders.default'), 'is_default' => true, 'bank_matching' => true])->id);
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Every folder of the active organisation, depth first, each level by name.
     *
     * @return list<array{folder: Folder, depth: int}>
     */
    public static function flat(): array
    {
        /** @var Collection<int, Folder> $all */
        $all = self::query()->get()->sortBy(static fn (Folder $folder): string => mb_strtolower($folder->name));
        $byParent = $all->groupBy(static fn (Folder $folder): string => $folder->parent_id ?? '');

        $walk = static function (string $parent, int $depth) use (&$walk, $byParent): array {
            $rows = [];
            foreach ($byParent->get($parent, []) as $folder) {
                $rows[] = ['folder' => $folder, 'depth' => $depth];
                array_push($rows, ...$walk($folder->id, $depth + 1));
            }

            return $rows;
        };

        return $walk('', 0);
    }

    /**
     * The id of a folder and of every folder below it (the filter shows the whole branch).
     *
     * @return list<string>
     */
    public static function branchIds(string $id): array
    {
        $ids = [$id];
        foreach (self::flat() as $row) {
            if (in_array($row['folder']->parent_id, $ids, true) && ! in_array($row['folder']->id, $ids, true)) {
                $ids[] = $row['folder']->id;
            }
        }

        return $ids;
    }

    /** 0 for a top folder. */
    public function depth(): int
    {
        foreach (self::flat() as $row) {
            if ($row['folder']->id === $this->id) {
                return $row['depth'];
            }
        }

        return 0;
    }
}
