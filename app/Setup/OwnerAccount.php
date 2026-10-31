<?php

declare(strict_types=1);

namespace App\Setup;

use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgRole;
use App\Domain\Org\Role;
use Illuminate\Support\Facades\DB;

/**
 * First organisation + owner with a password (Q-13: interim until passkeys, P1-4).
 * Used by the browser wizard and `dochamster:owner`.
 */
final class OwnerAccount
{
    public static function exists(): bool
    {
        return Membership::query()->where('role', Role::Owner->value)->whereNull('revoked_at')->exists();
    }

    public static function create(string $org, string $username, string $password): User
    {
        return DB::transaction(static function () use ($org, $username, $password): User {
            // After a reset that kept the receipts, the new owner takes over their organisation.
            $kept = self::exists() ? null : Organization::query()->oldest()->first();
            $organization = $kept ?? Organization::query()->create(['name' => $org]);
            $organization->update(['name' => $org]);
            // That reset removed the roles too.
            OrgRole::ensureDefaults($organization);

            $user = User::query()->create([
                'username' => mb_strtolower($username),
                'display_name' => $username,
                'password_hash' => $password,
            ]);
            Membership::query()->create(['org_id' => $organization->id, 'user_id' => $user->id, 'role' => Role::Owner]);

            return $user;
        });
    }
}
