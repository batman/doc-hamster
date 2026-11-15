<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use Illuminate\Support\Str;

/**
 * The one-time password of any person (point 215, PLAN §11.1 point 8): docHamster makes a strong random one
 * (24 characters, letters and digits – easy to read out), shown once to the Admin (App\Http\ShownOnce). The person
 * sets an own password at the first sign-in (`must_change_password`). A new one ends every sign-in of the person.
 */
final class OneTimePassword
{
    public const int LENGTH = 24;

    public static function generate(): string
    {
        return Str::password(self::LENGTH, symbols: false);
    }

    /** Sets a new one-time password and ends all sign-ins ("Angemeldet bleiben" included); returns it for the one-time display. */
    public static function renew(User $user): string
    {
        $password = self::generate();
        $user->forceFill(['password_hash' => $password, 'must_change_password' => true])->save();
        UserSessions::endOthers($user->id, null);
        UserSessions::rotateRememberToken($user);

        return $password;
    }

    /** The own password: the one-time flag goes, every other sign-in ends (the current one may stay). */
    public static function replace(User $user, #[\SensitiveParameter] string $password, ?string $keepSession): void
    {
        // The `hashed` cast makes the Argon2id hash.
        $user->forceFill(['password_hash' => $password, 'must_change_password' => false])->save();
        UserSessions::endOthers($user->id, $keepSession);
        if ($keepSession === null) {
            UserSessions::rotateRememberToken($user);
        }
    }
}
