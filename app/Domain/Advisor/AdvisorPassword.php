<?php

declare(strict_types=1);

namespace App\Domain\Advisor;

use App\Domain\Auth\User;
use App\Domain\Auth\UserSessions;
use Illuminate\Support\Str;

/**
 * The tax advisor's password (point 210, PLAN §11.1 point 8): docHamster makes a strong random one-time password
 * (24 characters, letters and digits – easy to read out, ≥ 20 as required), shown once to the owner (App\Http\ShownOnce).
 * A new one ends every sign-in of the advisor.
 */
final class AdvisorPassword
{
    public const int LENGTH = 24;

    public static function generate(): string
    {
        return Str::password(self::LENGTH, symbols: false);
    }

    /** Sets a new one-time password and ends all sign-ins; returns it for the one-time display. */
    public static function renew(User $user, AdvisorAccess $access): string
    {
        $password = self::generate();
        $user->forceFill(['password_hash' => $password])->save();
        $access->forceFill(['must_change_password' => true])->save();
        UserSessions::endOthers($user->id, null);
        UserSessions::rotateRememberToken($user);

        return $password;
    }
}
