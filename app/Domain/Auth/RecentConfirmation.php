<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Org\Role;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Point 212 (PLAN §11.1 point 6): a fresh confirmation before sensitive actions, like GitHub's "sudo mode". The session
 * keeps the moment of the last confirmation (sign-in counts too); for five minutes no second ask.
 *
 * Methods: today the sign-in password. Passkey hook: the app has no WebAuthn assertion flow yet (Passkey only stores
 * credentials) – once it has one, `methods()` returns 'passkey' for people with a passkey, the dialog offers it and a
 * verified assertion calls `mark()` just like a correct password.
 */
final class RecentConfirmation
{
    /** Laravel's own key (RequirePassword), so both mechanisms agree. */
    public const KEY = 'auth.password_confirmed_at';

    public const SECONDS = 300;

    public static function isFresh(?int $confirmedAt, int $now): bool
    {
        return $confirmedAt !== null && $confirmedAt <= $now && $now - $confirmedAt < self::SECONDS;
    }

    /** Seconds left in the window (0 = stale). */
    public static function secondsLeft(Session $session): int
    {
        $at = self::confirmedAt($session);
        $now = Carbon::now()->getTimestamp();

        return self::isFresh($at, $now) ? self::SECONDS - ($now - (int) $at) : 0;
    }

    /**
     * Whether this person has to confirm now. The tax advisor never does (he has no such actions); nor does somebody
     * without any way to confirm (no password, no passkey assertion yet) – there is nothing to ask for.
     */
    public static function required(?User $user, ?Role $role, Session $session): bool
    {
        if ($user === null || $role === Role::Accountant || self::methods($user) === []) {
            return false;
        }

        return self::secondsLeft($session) === 0;
    }

    /**
     * @return list<'password'>
     */
    public static function methods(User $user): array
    {
        return $user->hasPassword() ? ['password'] : [];
    }

    public static function checkPassword(User $user, string $password): bool
    {
        return $user->hasPassword() && Hash::check($password, $user->getAuthPassword());
    }

    public static function mark(Session $session): void
    {
        $session->put(self::KEY, Carbon::now()->getTimestamp());
    }

    private static function confirmedAt(Session $session): ?int
    {
        $at = $session->get(self::KEY);

        return is_int($at) || (is_string($at) && ctype_digit($at)) ? (int) $at : null;
    }
}
