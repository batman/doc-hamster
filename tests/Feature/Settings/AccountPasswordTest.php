<?php

declare(strict_types=1);

use App\Domain\Auth\RecentConfirmation;
use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
| Point 222 (A375): Mein Konto → Passwort ändern – the other sign-ins end, this one stays, a wrong current password is refused.
*/
uses(RefreshDatabase::class)->group('db');

function accountUser(): User
{
    $user = User::factory()->create(['password_hash' => 'das-alte-passwort-123', 'remember_token' => 'old-token']);
    Membership::factory()->create(['user_id' => $user->id, 'role' => Role::Member]);

    return $user;
}

function otherSession(User $user, string $id): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '192.0.2.1', 'user_agent' => 'Test', 'payload' => '', 'last_activity' => now()->getTimestamp()]);
}

it('ends the other sign-ins and keeps this one', function (): void {
    $user = accountUser();
    otherSession($user, 'other-phone');
    otherSession($user, 'other-laptop');
    $stranger = User::factory()->create();
    otherSession($stranger, 'stranger');

    $this->actingAs($user)->withSession([RecentConfirmation::KEY => now()->getTimestamp()])
        ->post('/settings/account/password', ['current' => 'das-alte-passwort-123', 'password' => 'ein-neues-passwort-456', 'password_confirmation' => 'ein-neues-passwort-456'])
        ->assertRedirect('/settings/account')->assertSessionHasNoErrors();

    $user->refresh();
    expect(Hash::check('ein-neues-passwort-456', (string) $user->password_hash))->toBeTrue()
        ->and($user->remember_token)->not->toBe('old-token')
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'stranger')->exists())->toBeTrue();
    $this->assertAuthenticatedAs($user);
    $this->get('/settings/account')->assertOk();
});

it('refuses a wrong current password', function (): void {
    $user = accountUser();
    otherSession($user, 'other-phone');

    $this->actingAs($user)->from('/settings/account')
        ->post('/settings/account/password', ['current' => 'falsch', 'password' => 'ein-neues-passwort-456', 'password_confirmation' => 'ein-neues-passwort-456'])
        ->assertRedirect('/settings/account')->assertSessionHasErrors('current');

    $user->refresh();
    expect(Hash::check('das-alte-passwort-123', (string) $user->password_hash))->toBeTrue()
        ->and($user->remember_token)->toBe('old-token')
        ->and(DB::table('sessions')->where('id', 'other-phone')->exists())->toBeTrue();
});

it('refuses a password shorter than 12 characters', function (): void {
    $user = accountUser();

    $this->actingAs($user)->from('/settings/account')
        ->post('/settings/account/password', ['current' => 'das-alte-passwort-123', 'password' => 'kurz', 'password_confirmation' => 'kurz'])
        ->assertSessionHasErrors('password');
});
