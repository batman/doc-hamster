<?php

declare(strict_types=1);

namespace App\Domain\Org;

use App\Domain\Auth\User;
use Carbon\CarbonImmutable;

/**
 * Whether a membership may be used right now (points 218, 223): not revoked, not paused (the person or the role),
 * and inside "Zugang von / bis" (both days included). Checked at sign-in, on every request (ResolveCurrentOrg, so it
 * ends mid-session) and for upload keys (api/upload answers 403).
 */
final class PersonAccess
{
    public static function allows(Membership $membership, ?CarbonImmutable $today = null): bool
    {
        return self::refusal($membership, $today) === null;
    }

    /** The reason in plain words, or null when the membership works today. */
    public static function refusal(Membership $membership, ?CarbonImmutable $today = null): ?string
    {
        $today ??= CarbonImmutable::today();
        if (! $membership->isActive()) {
            return (string) __('auth.access.revoked');
        }
        if ($membership->isPaused()) {
            return (string) __('auth.access.paused');
        }
        if ($membership->access_from !== null && $today->lessThan($membership->access_from)) {
            return (string) __('auth.access.not_yet', ['date' => $membership->access_from->format('d.m.Y')]);
        }
        if ($membership->access_until !== null && $today->greaterThan($membership->access_until)) {
            return (string) __('auth.access.expired', ['date' => $membership->access_until->format('d.m.Y')]);
        }

        return null;
    }

    /**
     * For the sign-in: null when at least one membership of the person works, else the reason of the first one.
     */
    public static function refusalFor(User $user): ?string
    {
        $memberships = $user->memberships()->whereNull('revoked_at')->with('orgRole')->oldest()->get();
        $first = null;
        foreach ($memberships as $membership) {
            $reason = self::refusal($membership);
            if ($reason === null) {
                return null;
            }
            $first ??= $reason;
        }

        return $first;
    }
}
