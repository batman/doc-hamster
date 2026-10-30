<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| MVP: owners add employees and tax advisors (D-012).
*/
uses(RefreshDatabase::class)->group('db');

const OWNER_PASSWORD = 'ein-langes-test-passwort';

beforeEach(function (): void {
    $this->owner = User::factory()->create(['password_hash' => OWNER_PASSWORD]);
    $this->ownership = Membership::factory()->create(['user_id' => $this->owner->id, 'role' => Role::Owner]);
});

it('creates a tax advisor who can log in with the password shown once', function (): void {
    $response = $this->actingAs($this->owner)->post('/members', [
        'display_name' => 'Kanzlei Muster', 'username' => 'Kanzlei', 'role' => 'accountant', 'password' => OWNER_PASSWORD,
    ]);

    $response->assertRedirect(route('members.index'));
    $shown = session('new_password');
    $advisor = User::query()->where('username', 'kanzlei')->firstOrFail();

    expect(strlen($shown['password']))->toBeGreaterThanOrEqual(20)
        ->and($advisor->memberships()->firstOrFail()->role)->toBe(Role::Accountant)
        ->and($advisor->memberships()->firstOrFail()->org_id)->toBe($this->ownership->org_id);

    $this->get('/members')->assertSee($shown['password']);
    $this->get('/members')->assertDontSee($shown['password']);

    $this->post('/logout');
    $this->post('/login', ['username' => 'kanzlei', 'password' => $shown['password']])->assertRedirect('/');
    $this->assertAuthenticatedAs($advisor);
});

it('needs the owner password and is closed to everyone else', function (): void {
    $this->actingAs($this->owner)
        ->post('/members', ['display_name' => 'X', 'username' => 'x', 'role' => 'member', 'password' => 'falsch'])
        ->assertSessionHasErrors('password');

    $member = Membership::factory()->create(['org_id' => $this->ownership->org_id]);
    $this->actingAs($member->user)->get('/members')->assertForbidden();
});

it('cannot create a second owner or touch other organisations', function (): void {
    $this->actingAs($this->owner)
        ->post('/members', ['display_name' => 'X', 'username' => 'x', 'role' => 'owner', 'password' => OWNER_PASSWORD])
        ->assertSessionHasErrors('role');

    $foreign = Membership::factory()->create();
    $this->post('/members/'.$foreign->id.'/revoke', ['password' => OWNER_PASSWORD])->assertNotFound();
});

it('removes access at once, ends sessions and protects the own account', function (): void {
    $member = Membership::factory()->create(['org_id' => $this->ownership->org_id]);
    DB::table('sessions')->insert(['id' => 'member-session', 'user_id' => $member->user_id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($this->owner)->post('/members/'.$member->id.'/revoke', ['password' => OWNER_PASSWORD])->assertRedirect();

    expect($member->fresh()?->isActive())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'member-session')->exists())->toBeFalse();

    $this->actingAs($member->user)->get('/')->assertForbidden();

    $this->actingAs($this->owner)->post('/members/'.$this->ownership->id.'/revoke', ['password' => OWNER_PASSWORD])->assertForbidden();
});
