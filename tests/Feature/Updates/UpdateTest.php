<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\Role;
use App\Domain\Org\RoleRight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
| Owner-triggered update check. GitHub is faked; nothing leaves the test.
*/
uses(RefreshDatabase::class)->group('db');

function fakeRelease(string $version, string $installer = '<?php // new', ?string $served = null): void
{
    // Release downloads only, never the GitHub API (A87).
    Http::fake([
        'github.com/*/releases/latest/download/manifest.json' => Http::response(['version' => $version, 'php' => '8.3.0', 'installer_sha256' => hash('sha256', $installer)]),
        'github.com/*/releases/download/v'.$version.'/install.php' => Http::response($served ?? $installer),
        'github.com/*/releases/download/previews/previews.json' => Http::response('', 404),
        'api.github.com/*' => Http::response('', 403),
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
    $this->get('/update')->assertSee('1.0.0 → 1.1.0', false);

    // The page asks by Ajax and gets the list for its card only (point 8).
    $this->postJson('/update/check')->assertOk()->assertJsonPath('html', fn (string $html): bool => str_contains($html, '1.0.0 → 1.1.0') && str_contains($html, 'verfügbar'));
});

it('says so when already up to date', function (): void {
    fakeRelease('1.0.0');

    $this->post('/update/check')->assertSessionHas('status', __('updates.up_to_date'));
});

it('stays calm when GitHub is unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('down'));

    $this->post('/update/check')->assertSessionHasErrors('update');
});

it('is for owners and people with the update box, and needs the password to install', function (): void {
    fakeRelease('1.1.0');

    $this->post('/update/install', ['password' => 'falsch'])->assertSessionHasErrors('password');

    $member = Membership::factory()->create();
    $this->actingAs($member->user)->get('/update')->assertForbidden();

    // "System aktualisieren" opens the page; previews need their own box on top (PO 2026-11-07).
    $updater = Membership::factory()->withRights(RoleRight::Update)->create();
    $this->actingAs($updater->user)->get('/update')->assertOk()->assertDontSee('update/install-preview');
    $this->post('/update/install-preview')->assertForbidden();

    $onlyPreview = Membership::factory()->withRights(RoleRight::UpdatePreview)->create();
    $this->actingAs($onlyPreview->user)->get('/update')->assertForbidden();

    $both = Membership::factory()->withRights(RoleRight::Update, RoleRight::UpdatePreview)->create();
    $this->actingAs($both->user)->get('/update')->assertOk();
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

it('hands over to install.php for an update without questions and says when it is done', function (): void {
    fakeRelease('1.1.0', '<?php // installer');

    $webRoot = dirname(base_path()).'/dh-test-web-'.uniqid();
    mkdir($webRoot);

    $response = $this->withServerVariables(['SCRIPT_FILENAME' => $webRoot.'/index.php'])
        ->post('/update/install', ['password' => 'ein-langes-test-passwort']);

    @unlink($webRoot.'/install.php');
    @unlink(dirname($webRoot).'/dochamster-install-code.txt');
    @rmdir($webRoot);

    expect($response->headers->get('Location'))->toMatch('#/install\.php\?code=[0-9A-F]{20}&update=1$#');
    $this->get('/update?updated=1')->assertSee('Fertig: docHamster ist jetzt auf Version 1.0.0.');
});

function fakePreviews(string $sha): void
{
    Http::fake([
        'github.com/*/releases/latest/download/manifest.json' => Http::response(['version' => '1.1.0', 'php' => '8.3.0', 'installer_sha256' => 'x', 'date' => '2026-11-10']),
        'github.com/*/releases/download/previews/previews.json' => Http::response([
            ['number' => 116, 'title' => 'Framework', 'branch' => 'lead/framework', 'sha' => $sha, 'date' => '2026-11-12T08:00:00Z'],
            ['number' => 129, 'title' => 'Neue Update-Seite', 'branch' => 'dev', 'sha' => str_repeat('c', 40)],
        ]),
        '*' => Http::response('', 500),
    ]);
}

it('lists Main and the preview branches with one click, the installed one marked', function (): void {
    fakePreviews(str_repeat('b', 40));
    $webRoot = dirname(base_path()).'/dh-test-web-'.uniqid();
    mkdir($webRoot);

    $html = $this->withServerVariables(['SCRIPT_FILENAME' => $webRoot.'/index.php'])->postJson('/update/check')->assertOk()->json('html');
    @rmdir($webRoot);

    expect($html)->toContain('Hauptversion', '1.0.0 → 1.1.0', '#116 · lead/framework', '#129 · dev', 'Neue Update-Seite', 'is-marked', 'Wechseln');
    Http::assertSentCount(2);
});

it('keeps an update in the installed preview branch and offers Main only as a switch', function (): void {
    config(['dochamster.version' => 'preview-116-aaaaaaa']);
    fakePreviews(str_repeat('b', 40));
    $webRoot = dirname(base_path()).'/dh-test-web-'.uniqid();
    mkdir($webRoot);

    $html = $this->withServerVariables(['SCRIPT_FILENAME' => $webRoot.'/index.php'])->postJson('/update/check')->assertOk()->json('html');
    @rmdir($webRoot);

    // The preview row offers its newer state; Main is a switch, not an update.
    expect($html)->toContain('aaaaaaa → bbbbbbb', 'data-dialog-open="update-preview"', 'data-preview-pr="116"', 'data-dialog-open="switch-main"')
        ->not->toContain('data-dialog-open="update-main"');

    // "Aktualisieren" on Main never leaves a preview branch.
    $this->post('/update/install', ['password' => 'ein-langes-test-passwort'])->assertSessionHasErrors(['update' => __('updates.stay_in_branch')]);
    $this->get('/update')->assertSee(__('updates.main'));
});
