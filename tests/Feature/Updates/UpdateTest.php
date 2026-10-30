<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
| Owner-triggered update check. GitHub is faked; nothing leaves the test.
*/
uses(RefreshDatabase::class)->group('db');

function fakeRelease(string $version, string $installer = '<?php // new', ?string $served = null): void
{
    Http::fake([
        'api.github.com/*' => Http::response([['draft' => false, 'prerelease' => false, 'assets' => [
            ['name' => 'manifest.json', 'browser_download_url' => 'https://example.com/manifest.json'],
            ['name' => 'install.php', 'browser_download_url' => 'https://example.com/install.php'],
        ]]]),
        'example.com/manifest.json' => Http::response(['version' => $version, 'php' => '8.3.0', 'installer_sha256' => hash('sha256', $installer)]),
        'example.com/install.php' => Http::response($served ?? $installer),
    ]);
}

beforeEach(function (): void {
    config(['dochamster.version' => '1.0.0']);
    $this->owner = User::factory()->create(['password_hash' => 'ein-langes-test-passwort']);
    Membership::factory()->create(['user_id' => $this->owner->id, 'role' => Role::Owner]);
    $this->actingAs($this->owner);
});

it('does not contact GitHub just by opening the page', function (): void {
    Http::fake();

    $this->get('/update')->assertOk();

    Http::assertNothingSent();
});

it('reports a newer version only after the click', function (): void {
    fakeRelease('1.1.0');

    $this->post('/update/check')->assertRedirect();
    $this->get('/update')->assertSee('Version 1.1.0 ist verfügbar');
});

it('says so when already up to date', function (): void {
    fakeRelease('1.0.0');

    $this->post('/update/check')->assertSessionHas('status', __('updates.up_to_date'));
});

it('stays calm when GitHub is unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('down'));

    $this->post('/update/check')->assertSessionHasErrors('update');
});

it('is for owners only and needs the password to install', function (): void {
    fakeRelease('1.1.0');

    $this->post('/update/install', ['password' => 'falsch'])->assertSessionHasErrors('password');

    $member = Membership::factory()->create();
    $this->actingAs($member->user)->get('/update')->assertForbidden();
});

it('refuses an installer whose checksum does not match', function (): void {
    fakeRelease('1.1.0', '<?php // real', '<?php // tampered');

    // A web root next to the app folder, the layout install.php creates.
    $webRoot = dirname(base_path()).'/dh-test-web-'.uniqid();
    mkdir($webRoot);

    $response = $this->withServerVariables(['SCRIPT_FILENAME' => $webRoot.'/index.php'])
        ->post('/update/install', ['password' => 'ein-langes-test-passwort']);

    $written = file_exists($webRoot.'/install.php');
    @rmdir($webRoot);

    $response->assertSessionHasErrors('update');
    expect($written)->toBeFalse();
});
