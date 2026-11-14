<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Point 208: a person's sign-ins, read from the database session table (config/session.php). Ending one deletes its
 * row; ending all others also rotates the remember token, so an "Angemeldet bleiben" cookie elsewhere cannot sign
 * back in.
 */
final class UserSessions
{
    /**
     * @return Collection<int, object{id: string, ip_address: string|null, user_agent: string|null, last_activity: int}>
     */
    public static function of(string $userId): Collection
    {
        /** @var Collection<int, object{id: string, ip_address: string|null, user_agent: string|null, last_activity: int}> $rows */
        $rows = DB::table('sessions')->where('user_id', $userId)->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        return $rows;
    }

    /** Ends one session of this person; false when there is no such row. */
    public static function end(string $userId, string $sessionId): bool
    {
        return DB::table('sessions')->where('user_id', $userId)->where('id', $sessionId)->delete() > 0;
    }

    /** Ends every session of this person except $keep (null: all); returns how many ended. */
    public static function endOthers(string $userId, ?string $keep): int
    {
        return DB::table('sessions')->where('user_id', $userId)
            ->when($keep !== null, static fn ($q) => $q->where('id', '!=', $keep))
            ->delete();
    }

    /** A new remember token: every "Angemeldet bleiben" cookie issued so far stops working. Returns the new token. */
    public static function rotateRememberToken(User $user): string
    {
        $token = Str::random(60);
        $user->setRememberToken($token);
        $user->timestamps = false;
        $user->save();
        $user->timestamps = true;

        return $token;
    }
}
