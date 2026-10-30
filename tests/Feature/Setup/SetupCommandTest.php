<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Org\Organization;
use App\Infrastructure\Environment\SystemProbe;
use App\Setup\EnvFile;
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
