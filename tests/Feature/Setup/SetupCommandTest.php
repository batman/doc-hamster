<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\Role;
use App\Infrastructure\Environment\SystemProbe;
use App\Setup\EnvFile;
use App\Setup\OwnerAccount;
use App\Setup\SetupCode;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeSystemProbe;

/*
| P1-10: interactive shell installation (D-024, AK-4.9.7).
*/

beforeEach(function (): void {
    $this->app->instance(SystemProbe::class, new FakeSystemProbe);
    $this->envPath = tempnam(sys_get_temp_dir(), 'env');
    $this->app->instance(EnvFile::class, new EnvFile($this->envPath));
});

afterEach(function (): void {
    @unlink($this->envPath);
});

/**
 * No RefreshDatabase here (the command purges the connection), so owner state
 * comes from the shared test database. These helpers pin it per test.
 *
 * @return list<array<string, mixed>>
 */
function snapshotOwnerRows(): array
{
    return Membership::query()->where('role', Role::Owner->value)->get()->map->getAttributes()->all();
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function restoreOwnerRows(array $rows): void
{
    if ($rows !== []) {
        Membership::insert($rows);
    }
}

/** The created user, if the test added the owner itself (null when one already existed). */
function ensureOwnerExists(): ?User
{
    if (OwnerAccount::exists()) {
        return null;
    }

    return OwnerAccount::create('Haushalt Bestand', 'inhaber-bestand', 'ein-langes-test-passwort');
}

function deleteOwnerUser(?User $user): void
{
    if ($user !== null) {
        Organization::query()->whereKey($user->memberships()->pluck('org_id'))->delete();
        $user->delete();
    }
}

it('stops without prompting when the database is unreachable and the run is not interactive', function (): void {
    config(['database.connections.'.config('database.default').'.host' => 'db.invalid']);

    $this->artisan('dochamster:setup', ['--no-interaction' => true])
        ->expectsOutputToContain('keine Verbindung zur Datenbank')
        ->assertExitCode(1);

    expect(Dotenv::parse((string) file_get_contents($this->envPath)))->not->toHaveKey('DOCHAMSTER_INSTALLED');
});

it('asks for address and database credentials, then finishes the installation', function (): void {
    $name = config('database.default');
    $real = config("database.connections.{$name}");
    config(["database.connections.{$name}.host" => 'db.invalid']);
    DB::purge($name);

    $this->artisan('dochamster:setup')
        ->expectsQuestion(__('setup.ask_url'), 'https://belege.example.com')
        ->expectsQuestion(__('setup.wizard.db_host'), $real['host'])
        ->expectsQuestion(__('setup.wizard.db_port'), (string) $real['port'])
        ->expectsQuestion(__('setup.wizard.db_name'), $real['database'])
        ->expectsQuestion(__('setup.wizard.db_user'), $real['username'])
        ->expectsQuestion(__('setup.wizard.db_password'), (string) $real['password'])
        ->expectsQuestion(__('auth.owner.org'), 'Haushalt Shell')
        ->expectsQuestion(__('auth.username'), 'inhaber-shell')
        ->expectsQuestion(__('auth.password'), 'ein-langes-test-passwort')
        ->expectsQuestion(__('setup.wizard.password_repeat'), 'ein-langes-test-passwort')
        ->expectsOutputToContain(__('setup.done'))
        ->assertExitCode(0);

    $stored = Dotenv::parse((string) file_get_contents($this->envPath));
    $owner = User::query()->where('username', 'inhaber-shell')->firstOrFail();

    expect($stored['APP_URL'])->toBe('https://belege.example.com')
        ->and($stored['DB_HOST'])->toBe($real['host'])
        ->and($stored['DOCHAMSTER_INSTALLED'])->toBe('true');

    // No RefreshDatabase (the command purges the connection) – clean up by hand.
    Organization::query()->whereKey($owner->memberships()->pluck('org_id'))->delete();
    $owner->delete();
})->group('db');

it('deletes a leftover web setup code when the shell install succeeds', function (): void {
    $created = ensureOwnerExists();
    $codePath = sys_get_temp_dir().'/setup-code-'.uniqid().'.txt';
    file_put_contents($codePath, "AAAAA-BBBBB-CCCCC-DDDDD\n");
    $this->app->instance(SetupCode::class, new SetupCode($codePath));

    try {
        $this->artisan('dochamster:setup', ['--no-interaction' => true])
            ->expectsOutputToContain(__('setup.done'))
            ->assertExitCode(0);

        expect(file_exists($codePath))->toBeFalse()
            ->and(Dotenv::parse((string) file_get_contents($this->envPath))['DOCHAMSTER_INSTALLED'])->toBe('true');
    } finally {
        @unlink($codePath);
        deleteOwnerUser($created);
    }
})->group('db');

it('keeps the web setup code when the shell install stops without an owner', function (): void {
    $owners = snapshotOwnerRows();
    Membership::query()->where('role', Role::Owner->value)->delete();

    try {
        $codePath = sys_get_temp_dir().'/setup-code-'.uniqid().'.txt';
        file_put_contents($codePath, "AAAAA-BBBBB-CCCCC-DDDDD\n");
        $this->app->instance(SetupCode::class, new SetupCode($codePath));

        $this->artisan('dochamster:setup', ['--no-interaction' => true])
            ->expectsOutputToContain(__('setup.owner_missing', ['command' => 'php artisan dochamster:owner']))
            ->assertExitCode(1);

        // The wizard is still open, so its code must survive the failed run.
        expect(file_exists($codePath))->toBeTrue()
            ->and(Dotenv::parse((string) file_get_contents($this->envPath)))->not->toHaveKey('DOCHAMSTER_INSTALLED');

        @unlink($codePath);
    } finally {
        restoreOwnerRows($owners);
    }
})->group('db');
