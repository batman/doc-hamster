<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Api\AccessKey;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;

/**
 * "Zugang für Assistenten" (D-040): the one door to docHamster's interface – the MCP address for Claude and the like,
 * the click sign-in (OAuth) and the access keys of Mein Konto. Off after install; only the owner opens or closes it
 * (Einstellungen → Assistenten) and ticks what assistants may do – nothing is ticked at first, and the door opens only
 * with at least one right. An assistant changes nothing itself: it files proposals the owner takes over or not.
 * Closed means: every address of the door answers "not here", no key works, and closing disconnects every assistant.
 */
final class AssistantAccess
{
    /** Read bookings, documents with their recognised text, folders and proposals. */
    public const READ = 'read';

    /** Look at page pictures (photos, scan pages) – to check the text recognition and to measure straightening. */
    public const IMAGES = 'images';

    /** Point 32, A85: read the Logbuch (never entries about documents in private folders). */
    public const LOG = 'log';

    public const PROPOSE_LINK = 'link';

    public const PROPOSE_MOVE = 'move';

    /** Point 33, A89: folders – create (with documents), rename, move, delete. */
    public const PROPOSE_FOLDERS = 'folders';

    /** Duplicates and deleting (always only into the Papierkorb). */
    public const PROPOSE_CLEANUP = 'cleanup';

    public const PROPOSE_SHRINK = 'shrink';

    /** Straightening and cutting out a photographed receipt. */
    public const PROPOSE_STRAIGHTEN = 'straighten';

    /** Corrections of recognised values (amount, date, sender …). */
    public const PROPOSE_CORRECT = 'correct';

    /** Switching a setting, e.g. "Dokumentgröße optimieren" on. */
    public const PROPOSE_SETTINGS = 'settings';

    /** In this order on the page. */
    public const RIGHTS = [self::READ, self::IMAGES, self::LOG, self::PROPOSE_LINK, self::PROPOSE_MOVE, self::PROPOSE_FOLDERS, self::PROPOSE_CLEANUP, self::PROPOSE_SHRINK, self::PROPOSE_STRAIGHTEN, self::PROPOSE_CORRECT, self::PROPOSE_SETTINGS];

    public static function enabled(?Organization $organization): bool
    {
        return data_get($organization?->settings_json, 'assistant.on') === true && self::rights($organization) !== [];
    }

    /** @return list<string> the ticked rights, in page order */
    public static function rights(?Organization $organization): array
    {
        $saved = data_get($organization?->settings_json, 'assistant.rights');

        return is_array($saved) ? array_values(array_intersect(self::RIGHTS, $saved)) : [];
    }

    public static function allows(?Organization $organization, string $right): bool
    {
        return self::enabled($organization) && in_array($right, self::rights($organization), true);
    }

    /** Any organisation on this server with the door open (the server-wide addresses exist only then). */
    public static function anyOpen(): bool
    {
        return Organization::query()->get(['id', 'settings_json'])->contains(static fn (Organization $o): bool => self::enabled($o));
    }

    /**
     * @param  list<string>|null  $rights  null keeps the ticked rights
     */
    public static function save(Organization $organization, bool $on, ?array $rights = null): void
    {
        $settings = $organization->settings_json ?? [];
        $rights = $rights === null ? self::rights($organization) : array_values(array_intersect(self::RIGHTS, $rights));
        $settings['assistant'] = ['on' => $on && $rights !== [], 'rights' => $rights];
        $organization->forceFill(['settings_json' => $settings])->save();
        if (! $settings['assistant']['on']) {
            AccessKey::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $organization->id)
                ->whereNotNull('client_id')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
    }
}
