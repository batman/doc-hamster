<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Auth\User;
use App\Setup\OwnerAccount;
use Illuminate\Console\Command;

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

        OwnerAccount::create($org, $username, $password);

        $this->info(__('auth.owner.created'));

        return self::SUCCESS;
    }
}
