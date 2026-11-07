<?php

declare(strict_types=1);

use App\Domain\Org\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| PHONE (issue #37): DocHamster on the home screen – manifest, offline page,
| service worker and the app bar.
*/
uses(RefreshDatabase::class)->group('db');

it('serves a web app manifest with icons for the home screen', function (): void {
    $response = $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json');

    $manifest = $response->json();
    expect($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toBe('/')
        ->and(collect($manifest['icons'])->pluck('sizes')->all())->toContain('192x192', '512x512');
    foreach ($manifest['icons'] as $icon) {
        expect(public_path(ltrim((string) parse_url($icon['src'], PHP_URL_PATH), '/')))->toBeFile();
    }
});

it('has an offline page without personal data or session token', function (): void {
    $member = Membership::factory()->owner()->create();
    $this->actingAs($member->user);

    $this->get('/offline')->assertOk()
        ->assertSee(__('app.shell.offline_title'))
        ->assertSee('data-queue-upload', false)
        ->assertDontSee('csrf-token', false)
        ->assertDontSee('name="_token"', false)
        ->assertDontSee($member->user->username);
});

it('ships a service worker that never keeps pages or documents', function (): void {
    $worker = (string) file_get_contents(public_path('sw.js'));

    expect($worker)->toContain("request.method !== 'GET'")
        ->toContain("request.mode === 'navigate'")
        ->toContain('(css|js|img)');
});

it('shows the app bar with camera and the home-screen tags when signed in', function (): void {
    $member = Membership::factory()->owner()->create();

    $this->actingAs($member->user)->get('/documents')->assertOk()
        ->assertSee('rel="manifest"', false)
        ->assertSee('class="app-bar"', false)
        ->assertSee('capture="environment"', false)
        ->assertSee('data-queue-upload', false)
        ->assertSee('name="csrf-token"', false);
});
