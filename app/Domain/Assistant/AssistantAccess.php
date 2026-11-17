<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Api\AccessKey;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;

/**
 * "Zugang für Assistenten" (D-040): the one door to docHamster's interface – the MCP address for Claude and the like,
 * the click sign-in (OAuth) and the access keys of Mein Konto. Off after install; only the owner opens or closes it
 * (Einstellungen → Assistenten). Closed means: every address of the door answers "not here", no key works, and
 * closing it disconnects every assistant of the organisation.
 */
final class AssistantAccess
{
    public static function enabled(?Organization $organization): bool
    {
        return data_get($organization?->settings_json, 'assistant.on') === true;
    }

    /** Any organisation on this server with the door open (the server-wide addresses exist only then). */
    public static function anyOpen(): bool
    {
        return Organization::query()->get(['id', 'settings_json'])->contains(static fn (Organization $o): bool => self::enabled($o));
    }

    public static function save(Organization $organization, bool $on): void
    {
        $settings = $organization->settings_json ?? [];
        $settings['assistant'] = ['on' => $on];
        $organization->forceFill(['settings_json' => $settings])->save();
        if (! $on) {
            AccessKey::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $organization->id)
                ->whereNotNull('client_id')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
    }
}
