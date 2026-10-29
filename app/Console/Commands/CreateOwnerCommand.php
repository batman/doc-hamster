<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates the first organisation and its owner with a password (Q-13: interim until passkeys, P1-4).
 */
final class CreateOwnerCommand extends Command
{
    protected $signature = 'dochamster:owner {--org=} {--username=} {--password=}';

    protected $description = 'Create the organisation and its owner account';

    public function handle(): int
    {
        $org = (string) ($this->option('org') ?? $this->ask(__('auth.owner.org')));
        $username = mb_strtolower((string) ($this->option('username') ?? $this->ask(__('auth.username'))));
        $password = (string) ($this->option('password') ?? $this->secret(__('auth.password')));

        if ($org === '' || $username === '' || mb_strlen($password) < 12) {
            $this->error(__('auth.owner.invalid'));

            return self::FAILURE;
        }

        if (User::query()->where('username', $username)->exists()) {
            $this->error(__('auth.owner.exists'));

            return self::FAILURE;
        }

        DB::transaction(static function () use ($org, $username, $password): void {
            $organization = Organization::query()->create(['name' => $org]);
            $user = User::query()->create(['username' => $username, 'display_name' => $username, 'password_hash' => $password]);
            Membership::query()->create(['org_id' => $organization->id, 'user_id' => $user->id, 'role' => Role::Owner]);
        });

        $this->info(__('auth.owner.created'));

        return self::SUCCESS;
    }
}
