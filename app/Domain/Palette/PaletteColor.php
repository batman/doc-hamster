<?php

declare(strict_types=1);

namespace App\Domain\Palette;

use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One colour of the organisation's shared palette (points 14-15): notes,
 * folders and tags pick from it. Items store the hex itself, so changing or
 * deleting a palette colour never changes an item.
 *
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property string $hex #rrggbb, lower case
 * @property int $position
 */
final class PaletteColor extends Model
{
    use BelongsToOrg;
    use HasUlids;

    /** The 20 sticky-note colours (PO, 2026-11-07) every organisation starts with; names from lang home.colors. */
    public const DEFAULTS = [
        'white' => '#ffffff', 'black' => '#000000', 'gray' => '#808080', 'red' => '#e53935', 'bordeaux' => '#800020',
        'orange' => '#fb8c00', 'yellow' => '#fdd835', 'lightgreen' => '#7cb342', 'green' => '#2e7d32', 'turquoise' => '#00acc1',
        'lightblue' => '#4fc3f7', 'blue' => '#1e5bc6', 'navy' => '#0d1b4c', 'purple' => '#8e24aa', 'lavender' => '#b39ddb',
        'pink' => '#ec407a', 'brown' => '#6d4c41', 'beige' => '#e8d8b9', 'gold' => '#d4af37', 'mint' => '#a5e6c8',
    ];

    public const HEX = '/^#[0-9a-f]{6}$/';

    protected $table = 'palette_colors';

    protected $fillable = ['org_id', 'name', 'hex', 'position'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * The active organisation's palette in its order (once per request).
     *
     * @return Collection<int, PaletteColor>
     */
    public static function ordered(): Collection
    {
        return self::forOrg(app(CurrentOrg::class)->id());
    }

    /**
     * @return Collection<int, PaletteColor>
     */
    private static function forOrg(string $orgId): Collection
    {
        return once(static fn (): Collection => self::query()->orderBy('position')->orderBy('id')->get());
    }

    /**
     * @return list<string>
     */
    public static function hexes(): array
    {
        return array_values(self::ordered()->map(static fn (self $color): string => (string) $color->hex)->all());
    }

    /** Framework colour class `c-rrggbb`; its rule comes from tags.css (the CSP allows no inline styles). */
    public static function cssClass(?string $hex): string
    {
        return is_string($hex) && preg_match(self::HEX, $hex) === 1 ? 'c-'.substr($hex, 1) : '';
    }

    /** Whether white text reads better on this colour. */
    public static function isDark(string $hex): bool
    {
        [$r, $g, $b] = array_map(static fn (string $part): float => hexdec($part) / 255, str_split(substr($hex, 1), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b <= 0.5;
    }

    /** The orange of the app (--primary): a coloured row keeps it for its icon while it stands out enough. */
    public const PRIMARY = '#ff7622';

    /** Text on a coloured row: dark or light, whichever reads better (point 83, A158). */
    public const INK_DARK = '#1f1e1d';

    public const INK_LIGHT = '#ffffff';

    /** WCAG relative luminance of #rrggbb (0 = black, 1 = white). */
    public static function luminance(string $hex): float
    {
        $channel = static function (string $part): float {
            $value = hexdec($part) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = array_map($channel, str_split(substr($hex, 1), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** WCAG contrast ratio of two colours (1 to 21). */
    public static function contrast(string $one, string $two): float
    {
        $a = self::luminance($one);
        $b = self::luminance($two);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * The colours of a row with this background ("farbige Zeile", A156-A160): text dark or light by WCAG contrast,
     * the icon orange while it reaches 3:1 against the row, else the text colour; the second line the text colour, faded.
     *
     * @return array{ink: string, sub: string, icon: string}
     */
    public static function rowColors(string $hex): array
    {
        $ink = self::contrast($hex, self::INK_DARK) >= self::contrast($hex, self::INK_LIGHT) ? self::INK_DARK : self::INK_LIGHT;
        $sub = $ink === self::INK_DARK ? 'rgb(31 30 29 / .72)' : 'rgb(255 255 255 / .8)';

        return ['ink' => $ink, 'sub' => $sub, 'icon' => self::contrast($hex, self::PRIMARY) >= 3 ? self::PRIMARY : $ink];
    }

    /** The CSS rule of one colour class: swatch, tag pill, note colour and coloured row. */
    public static function cssRule(string $hex): string
    {
        $class = self::cssClass($hex);
        $row = self::rowColors($hex);
        $rule = sprintf('.%s{--swatch:%s;--tag:%s;--note:%s;--row-bg:%s;--row-ink:%s;--row-sub:%s;--row-icon:%s}', $class, $hex, $hex, $hex, $hex, $row['ink'], $row['sub'], $row['icon']);

        return self::isDark($hex)
            ? $rule.sprintf('.note.%1$s{--note-ink:#fff}.note.%1$s a{color:inherit;text-decoration:underline}.note.%1$s .note-tool:hover{background:rgb(255 255 255 / .15)}', $class)
            : $rule;
    }

    /** Skips the model events: a new organisation gets its palette whichever organisation is active right now. */
    public static function addDefaults(Organization $organization): void
    {
        self::withoutEvents(static function () use ($organization): void {
            $position = 0;
            foreach (self::DEFAULTS as $key => $hex) {
                self::query()->create(['org_id' => $organization->id, 'name' => __('home.colors.'.$key), 'hex' => $hex, 'position' => $position++]);
            }
        });
    }
}
