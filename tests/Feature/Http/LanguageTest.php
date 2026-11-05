<?php

declare(strict_types=1);

use App\Domain\Org\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| The language selector on the settings page: the signed-in person's locale,
| applied by SetLocale (unsupported locales fall back, see LayoutTest).
*/
uses(RefreshDatabase::class)->group('db');

it('switches the display language from German to English and back', function (): void {
    $admin = Membership::factory()->owner()->create();

    $this->actingAs($admin->user)->get('/settings')->assertOk()
        ->assertSee('Einstellungen')
        ->assertSee('name="locale"', false);

    $this->post('/settings/language', ['locale' => 'en'])->assertRedirect(route('settings.index'));
    expect($admin->user->fresh()->locale)->toBe('en');

    $this->get('/settings')->assertOk()
        ->assertSee('Settings')
        ->assertSee('Display language')
        ->assertDontSee('Einstellungen');

    $this->post('/settings/language', ['locale' => 'de'])->assertRedirect(route('settings.index'));
    expect($admin->user->fresh()->locale)->toBe('de');

    $this->get('/settings')->assertOk()->assertSee('Einstellungen');
});

it('refuses unsupported locales and keeps the old one', function (): void {
    $admin = Membership::factory()->owner()->create();

    $this->actingAs($admin->user)->post('/settings/language', ['locale' => 'fr'])->assertSessionHasErrors('locale');
    expect($admin->user->fresh()->locale)->toBe('de');
});
