<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\Role;
use App\Setup\EnvFile;
use App\Setup\SetupCode;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Issue #6: a revoked owner must not make the wizard skip owner creation.
| Temp `.env` and code file like SetupWizardTest, plus RefreshDatabase so the
| membership state is exact (the wizard finish step never purges the connection).
*/

uses(RefreshDatabase::class)->group('db');

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

it('shows the owner form on finish when the only owner is revoked', function (): void {
    Membership::factory()->create(['role' => Role::Owner, 'revoked_at' => now()]);

    $this->get('/setup')->assertOk();
    $code = trim((string) file_get_contents($this->codePath));
    $this->post('/setup/unlock', ['code' => $code])->assertRedirect(route('setup.server'));

    $this->get('/setup/finish')->assertOk()->assertSee(__('setup.wizard.owner_text'));
});

it('creates the owner on finish when the only owner is revoked', function (): void {
    Membership::factory()->create(['role' => Role::Owner, 'revoked_at' => now()]);

    $this->get('/setup')->assertOk();
    $code = trim((string) file_get_contents($this->codePath));
    $this->post('/setup/unlock', ['code' => $code])->assertRedirect(route('setup.server'));

    $password = 'ein-langes-test-passwort';
    $this->post('/setup/finish', [
        'org' => 'Haushalt Neu', 'username' => 'inhaber-neu',
        'password' => $password, 'password_confirmation' => $password,
    ])->assertRedirect(route('login'));

    $owner = User::query()->where('username', 'inhaber-neu')->firstOrFail();

    expect($owner->memberships()->firstOrFail()->isActive())->toBeTrue()
        ->and(Dotenv::parse((string) file_get_contents($this->envPath))['DOCHAMSTER_INSTALLED'])->toBe('true')
        ->and(file_exists($this->codePath))->toBeFalse();
});

it('hides the owner form on finish when an active owner exists', function (): void {
    Membership::factory()->create(['role' => Role::Owner]);

    $this->get('/setup')->assertOk();
    $code = trim((string) file_get_contents($this->codePath));
    $this->post('/setup/unlock', ['code' => $code])->assertRedirect(route('setup.server'));

    $this->get('/setup/finish')->assertOk()->assertDontSee(__('setup.wizard.owner_text'));
});
