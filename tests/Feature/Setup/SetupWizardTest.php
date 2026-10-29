<?php

declare(strict_types=1);

use App\Setup\EnvFile;
use App\Setup\SetupCode;
use Dotenv\Dotenv;

/*
| P1-9: setup wizard (D-024). `.env` and the code file are temp files here.
*/

beforeEach(function (): void {
    config(['dochamster.installed' => false]);
    $this->envPath = tempnam(sys_get_temp_dir(), 'env');
    $this->codePath = sys_get_temp_dir().'/setup-code-'.uniqid().'.txt';
    $this->app->instance(EnvFile::class, new EnvFile($this->envPath));
    $this->app->instance(SetupCode::class, new SetupCode($this->codePath));
});

afterEach(function (): void {
    @unlink($this->envPath);
    @unlink($this->codePath);
});

function storedEnv(string $path): array
{
    return Dotenv::parse((string) file_get_contents($path));
}

function unlockWizard(object $test): void
{
    $test->get('/setup')->assertOk();
    $code = trim((string) file_get_contents($test->codePath));
    $test->post('/setup/unlock', ['code' => strtolower($code)])->assertRedirect(route('setup.server'));
}

it('sends every page to the wizard until installed, then hides the wizard', function (): void {
    $this->get('/')->assertRedirect('/setup');

    config(['dochamster.installed' => true]);

    $this->get('/setup')->assertNotFound();
    $this->get('/')->assertOk();
});

it('unlocks only with the code from the file and then stores the address as APP_URL', function (): void {
    $this->get('/setup')->assertOk()->assertSee('setup-code-');
    $this->get('/setup/server')->assertForbidden();

    $this->post('/setup/unlock', ['code' => 'AAAAA-BBBBB-CCCCC-DDDDD'])->assertSessionHasErrors('code');

    unlockWizard($this);

    expect(storedEnv($this->envPath)['APP_URL'])->toBe('https://dochamster.example.com');
});

it('explains wrong database credentials without details', function (): void {
    unlockWizard($this);

    $this->post('/setup/database', ['host' => 'db;unix_socket=/tmp/x', 'port' => 3306, 'database' => 'x', 'username' => 'u'])
        ->assertSessionHasErrors(['database' => __('setup.wizard.db_errors.invalid_input')]);
});

it('sets up the database, then locks itself on finish', function (): void {
    $connection = config('database.connections.'.config('database.default'));
    unlockWizard($this);

    $this->post('/setup/database', [
        'host' => $connection['host'], 'port' => (int) $connection['port'], 'database' => $connection['database'],
        'username' => $connection['username'], 'password' => 'definitely-wrong',
    ])->assertSessionHasErrors(['database' => __('setup.wizard.db_errors.access_denied')]);

    $this->post('/setup/database', [
        'host' => $connection['host'], 'port' => (int) $connection['port'], 'database' => $connection['database'],
        'username' => $connection['username'], 'password' => (string) $connection['password'],
    ])->assertRedirect(route('setup.finish'));

    expect(storedEnv($this->envPath)['DB_HOST'])->toBe($connection['host']);

    $this->post('/setup/finish')->assertRedirect('/');

    expect(storedEnv($this->envPath)['DOCHAMSTER_INSTALLED'])->toBe('true')
        ->and(file_exists($this->codePath))->toBeFalse();
})->group('db');
