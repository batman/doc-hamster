<?php

declare(strict_types=1);

use App\Domain\Auth\RecentConfirmation;
use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\Role;
use App\Http\Middleware\RequireRecentConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/*
| Point 212: "Bitte bestätigen" (sudo mode) – the five-minute window, which routes need it, nobody without a password asked.
*/
uses(RefreshDatabase::class)->group('db');

it('counts a confirmation as fresh for five minutes only', function (): void {
    $now = 1_800_000_000;

    expect(RecentConfirmation::isFresh(null, $now))->toBeFalse()
        ->and(RecentConfirmation::isFresh($now, $now))->toBeTrue()
        ->and(RecentConfirmation::isFresh($now - 299, $now))->toBeTrue()
        ->and(RecentConfirmation::isFresh($now - 300, $now))->toBeFalse()
        ->and(RecentConfirmation::isFresh($now + 5, $now))->toBeFalse();
});

it('puts every sensitive route behind the middleware', function (): void {
    foreach (RequireRecentConfirmation::ROUTES as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route {$name} is missing")
            ->and($route?->gatherMiddleware())->toContain(RequireRecentConfirmation::ALIAS);
    }
    expect(RequireRecentConfirmation::ROUTES)->toContain('exports.store', 'backup.download', 'documents.bulk.purge', 'documents.trash.empty', 'members.role', 'roles.destroy', 'devices.rekey', 'sessions.others', 'updates.install');
    // Everyday actions stay free.
    expect(Route::getRoutes()->getByName('documents.bulk.trash')?->gatherMiddleware())->not->toContain(RequireRecentConfirmation::ALIAS);
});

it('asks a fetch with 423, a form by going back, and lets it pass once confirmed', function (): void {
    $user = User::factory()->create(['password_hash' => 'ein-langes-test-passwort']);
    Membership::factory()->create(['user_id' => $user->id, 'role' => Role::Owner]);
    $this->actingAs($user);

    $this->postJson('/settings/devices', ['name' => 'iPhone', 'kind' => 'iphone'])
        ->assertStatus(423)->assertJson(['confirm' => true])->assertHeader('X-DocHamster-Confirm', 'required');
    $this->from('/settings/devices')->post('/settings/devices', ['name' => 'iPhone', 'kind' => 'iphone'])
        ->assertRedirect('/settings/devices')->assertSessionHas('confirm_required', true);
    $this->getJson('/confirm/status')->assertJson(['fresh' => false]);

    $this->postJson('/confirm', ['password' => 'falsch'])->assertJsonValidationErrors('password');
    $this->postJson('/confirm', ['password' => 'ein-langes-test-passwort'])->assertOk()->assertJson(['confirmed' => true]);

    $this->getJson('/confirm/status')->assertJson(['fresh' => true]);
    $this->post('/settings/devices', ['name' => 'iPhone', 'kind' => 'iphone'])->assertRedirectContains('shown=');

    $this->travel(301)->seconds();
    $this->postJson('/settings/devices', ['name' => 'iPad', 'kind' => 'iphone'])->assertStatus(423);
});

it('asks everyone with a password, nobody without one', function (): void {
    $user = User::factory()->create(['password_hash' => 'ein-langes-test-passwort']);

    expect(RecentConfirmation::required($user, app('session.store')))->toBeTrue()
        ->and(RecentConfirmation::required(User::factory()->create(), app('session.store')))->toBeFalse();
});
