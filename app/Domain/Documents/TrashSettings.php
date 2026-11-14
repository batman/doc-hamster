<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Org\Organization;

/**
 * Point 207: how long documents stay in the Papierkorb before the daily job empties it for good (0 = never), and how
 * long "Papierkorb leeren" can be undone. Per organisation in settings_json.trash.
 */
final class TrashSettings
{
    /** Days in the Papierkorb; 0 = never empty automatically. */
    public const array KEEP = [7, 30, 90, 365, 0];

    public const int KEEP_DEFAULT = 30;

    /** Days "Papierkorb leeren" can be undone. */
    public const array UNDO = [7, 14, 30];

    public const int UNDO_DEFAULT = 14;

    /** @return array{keep: int, undo: int} */
    public static function of(?Organization $organization): array
    {
        $saved = data_get($organization?->settings_json, 'trash');
        $saved = is_array($saved) ? $saved : [];
        $keep = $saved['keep'] ?? null;
        $undo = $saved['undo'] ?? null;

        return [
            'keep' => is_int($keep) && in_array($keep, self::KEEP, true) ? $keep : self::KEEP_DEFAULT,
            'undo' => is_int($undo) && in_array($undo, self::UNDO, true) ? $undo : self::UNDO_DEFAULT,
        ];
    }

    /** @param  array{keep?: int, undo?: int}  $values */
    public static function save(Organization $organization, array $values): void
    {
        $settings = $organization->settings_json ?? [];
        $settings['trash'] = [...self::of($organization), ...array_intersect_key($values, ['keep' => 1, 'undo' => 1])];
        $organization->forceFill(['settings_json' => $settings])->save();
    }
}
