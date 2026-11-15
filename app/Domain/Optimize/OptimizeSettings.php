<?php

declare(strict_types=1);

namespace App\Domain\Optimize;

use App\Domain\Org\Organization;

/**
 * Point 236: the settings of "Dokumentgröße optimieren" per organisation (settings_json.optimize). Off after install
 * (F46); the threshold in MB per page (default 2, A406); where the original goes – into the Papierkorb (default, F45)
 * or into an archive folder of its own.
 *
 * @phpstan-type Settings array{on: bool, mb: float, original: string, folder: string|null}
 */
final class OptimizeSettings
{
    public const string TRASH = 'trash';

    public const string ARCHIVE = 'archive';

    public const float MB_DEFAULT = 2.0;

    public const float MB_MIN = 0.5;

    public const float MB_MAX = 50.0;

    /** @return Settings */
    public static function of(?Organization $organization): array
    {
        $saved = data_get($organization?->settings_json, 'optimize');
        $saved = is_array($saved) ? $saved : [];
        $mb = $saved['mb'] ?? null;
        $folder = $saved['folder'] ?? null;

        return [
            'on' => ($saved['on'] ?? false) === true,
            'mb' => is_int($mb) || is_float($mb) ? self::clampMb((float) $mb) : self::MB_DEFAULT,
            'original' => ($saved['original'] ?? null) === self::ARCHIVE ? self::ARCHIVE : self::TRASH,
            'folder' => is_string($folder) && $folder !== '' ? $folder : null,
        ];
    }

    public static function enabled(?Organization $organization): bool
    {
        return self::of($organization)['on'];
    }

    /** The threshold in bytes per page. */
    public static function bytesPerPage(?Organization $organization): int
    {
        return (int) round(self::of($organization)['mb'] * 1024 * 1024);
    }

    /** @param  array{on?: bool, mb?: float, original?: string, folder?: string|null}  $values */
    public static function save(Organization $organization, array $values): void
    {
        $settings = $organization->settings_json ?? [];
        $settings['optimize'] = [...self::of($organization), ...array_intersect_key($values, ['on' => 1, 'mb' => 1, 'original' => 1, 'folder' => 1])];
        $organization->forceFill(['settings_json' => $settings])->save();
    }

    public static function clampMb(float $mb): float
    {
        return round(max(self::MB_MIN, min(self::MB_MAX, $mb)), 1);
    }
}
