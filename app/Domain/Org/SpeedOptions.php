<?php

declare(strict_types=1);

namespace App\Domain\Org;

use Illuminate\Support\Facades\Cache;

/**
 * Point 164.7: the options of Einstellungen → Geschwindigkeit, per organisation (settings_json.speed).
 * prefetch: what the browser loads before it is clicked (windows.js); thumbs: size and quality of new thumbnails.
 */
final class SpeedOptions
{
    public const array PREFETCH = ['off', 'normal', 'strong'];

    public const array THUMBS = ['small', 'normal'];

    /** Thumbnail edge in pixels and JPEG quality per choice. */
    public const array THUMB_SIZES = ['small' => [320, 70], 'normal' => [480, 80]];

    /** @return array{prefetch: string, thumbs: string} */
    public static function of(?Organization $organization): array
    {
        $saved = data_get($organization?->settings_json, 'speed');
        $saved = is_array($saved) ? $saved : [];

        return [
            'prefetch' => in_array($saved['prefetch'] ?? null, self::PREFETCH, true) ? $saved['prefetch'] : 'normal',
            'thumbs' => in_array($saved['thumbs'] ?? null, self::THUMBS, true) ? $saved['thumbs'] : 'normal',
        ];
    }

    /** @param  array{prefetch?: string, thumbs?: string}  $values */
    public static function save(Organization $organization, array $values): void
    {
        $settings = $organization->settings_json ?? [];
        $settings['speed'] = [...self::of($organization), ...array_intersect_key($values, ['prefetch' => 1, 'thumbs' => 1])];
        $organization->forceFill(['settings_json' => $settings])->save();
        Cache::memo()->forever(self::prefetchKey($organization->id), $settings['speed']['prefetch']);
    }

    /** The prefetch choice for the layout, from the cache it preloads (no query of its own). */
    public static function prefetch(string $orgId): string
    {
        $value = Cache::memo()->get(self::prefetchKey($orgId));

        return is_string($value) && in_array($value, self::PREFETCH, true) ? $value : 'normal';
    }

    public static function prefetchKey(string $orgId): string
    {
        return 'speed.prefetch.'.$orgId;
    }

    /** @return array{0: int, 1: int} edge in pixels, JPEG quality */
    public static function thumbSize(?Organization $organization): array
    {
        return self::THUMB_SIZES[self::of($organization)['thumbs']];
    }
}
