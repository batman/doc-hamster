<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

use App\Domain\Org\Organization;
use Illuminate\Support\Facades\Cache;

/**
 * Point 186.1: "Server-Texterkennung" on or off per organisation (settings_json.ocr.server, on by default). Off = the
 * browser reads everything; the server reads only when asked for one document ("Mit Server neu erkennen", 186.3).
 * Cached per organisation, since LocalOcr::mode() asks on every page.
 */
final class OcrServerSwitch
{
    public static function enabled(string $orgId): bool
    {
        $value = Cache::memo()->rememberForever(self::key($orgId), static function () use ($orgId): string {
            $saved = data_get(Organization::query()->find($orgId)?->settings_json, 'ocr.server');

            return $saved === false ? 'off' : 'on';
        });

        return $value !== 'off';
    }

    public static function save(Organization $organization, bool $on): void
    {
        $settings = $organization->settings_json ?? [];
        $ocr = is_array($settings['ocr'] ?? null) ? $settings['ocr'] : [];
        $settings['ocr'] = [...$ocr, 'server' => $on];
        $organization->forceFill(['settings_json' => $settings])->save();
        Cache::memo()->forever(self::key($organization->id), $on ? 'on' : 'off');
    }

    public static function key(string $orgId): string
    {
        return 'ocr.server.'.$orgId;
    }
}
