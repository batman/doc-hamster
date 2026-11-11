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
 * @property string|null $color #rrggbb, or null for the text colour
 * @property string|null $icon one of ICONS, or null for the standard symbol
 * @property int|null $position order among its siblings (drag and drop); null = after the ordered ones
 * @property bool $is_default the folder "Belege allgemein": cannot be deleted, renamed or moved
 * @property bool $bank_matching whether the bank matching may use receipts of this folder
 */
final class Folder extends Model
{
    use BelongsToOrg;
    use HasUlids;

    /** Levels below the top: a deeper tree is hard to use on a phone. */
    public const MAX_DEPTH = 4;

    /** The symbols a folder may have (PO, 2026-11-07: a fixed set of 20 to 30). Tabler icons in resources/icons/tabler. */
    public const ICONS = [
        'folder', 'receipt', 'receipt-2', 'file-invoice', 'file-text', 'briefcase', 'building', 'building-bank',
        'home', 'car', 'gas-station', 'plane', 'shopping-cart', 'tools', 'tools-kitchen-2', 'device-laptop',
        'phone', 'heart', 'stethoscope', 'school', 'gift', 'star', 'user', 'users', 'pig-money', 'calculator',
    ];

    /** Key in the organisation's settings_json: where the Papierkorb stands among the top folders. */
    public const TRASH_SLOT = 'folders.trash_slot';

    protected $fillable = ['org_id', 'parent_id', 'name', 'color', 'icon', 'position', 'is_default', 'bank_matching'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'bank_matching' => 'boolean', 'position' => 'integer'];
    }

    /** The symbol to draw: the chosen one, else a receipt for the default folder and a folder for the others. */
    public function iconName(): string
    {
        return is_string($this->icon) && in_array($this->icon, self::ICONS, true) ? $this->icon : ($this->is_default ? 'file-text' : 'folder');
    }

    /** CSS class that gives the symbol its colour (rules come from tags.css; the CSP allows no inline styles). */
    public function colorClass(): string
    {
        return $this->color !== null ? 'fc-'.$this->id : '';
    }

    /**
     * Skips the model events: a new organisation gets its folder whichever
     * organisation is active right now.
     */
    public static function addDefaults(Organization $organization): void
    {
        self::withoutEvents(static function () use ($organization): void {
            self::query()->create(['org_id' => $organization->id, 'name' => __('folders.default'), 'is_default' => true, 'bank_matching' => true]);
            self::query()->create(['org_id' => $organization->id, 'name' => __('folders.invoices'), 'icon' => 'file-invoice', 'bank_matching' => true]);
        });
    }

    /** The "Belege allgemein" folder of an organisation; made if an old organisation has none yet. */
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
     * Every folder of the active organisation, depth first; each level in the
     * order set by drag and drop, then the default folder, then by name.
     *
     * @return list<array{folder: Folder, depth: int}>
     */
    public static function flat(): array
    {
        /** @var Collection<int, Folder> $all */
        $all = self::query()->get()->sortBy(static fn (Folder $folder): string => sprintf('%010d|%d|%s', $folder->position ?? 9999999999, $folder->is_default ? 0 : 1, mb_strtolower($folder->name)));
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

    /**
     * Where the Papierkorb stands among the top folders: 0 = first; null = last (PO, 2026-11-07).
     */
    public static function trashSlot(?Organization $organization): ?int
    {
        $slot = data_get($organization?->settings_json, self::TRASH_SLOT);

        return is_int($slot) && $slot >= 0 ? $slot : null;
    }

    /**
     * The menu under "Belege": the folder tree with the Papierkorb (null) among the top folders.
     *
     * @return list<array{folder: Folder|null, depth: int}>
     */
    public static function menu(?Organization $organization): array
    {
        $slot = self::trashSlot($organization);
        $rows = [];
        $top = 0;
        foreach (self::flat() as $row) {
            if ($row['depth'] === 0) {
                if ($top === $slot) {
                    $rows[] = ['folder' => null, 'depth' => 0];
                }
                $top++;
            }
            $rows[] = $row;
        }
        if ($slot === null || $slot >= $top) {
            $rows[] = ['folder' => null, 'depth' => 0];
        }

        return $rows;
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
