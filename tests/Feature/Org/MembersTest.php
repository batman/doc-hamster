<?php

declare(strict_types=1);

use App\Domain\Auth\RecentConfirmation;
use App\Domain\Auth\User;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgRole;
use App\Domain\Org\OrgScope;
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
    $this->confirmed();
});

it('creates a tax advisor who can log in with the password shown once', function (): void {
    $advisorRole = OrgRole::query()->withoutGlobalScope(OrgScope::class)
        ->where('org_id', $this->ownership->org_id)->where('system_key', 'accountant')->firstOrFail();

    $response = $this->actingAs($this->owner)->post('/members', [
        'display_name' => 'Kanzlei Muster', 'username' => 'Kanzlei', 'role' => $advisorRole->id,
    ]);

    // The password waits in the cache under a token in the address (no flash that other requests could eat).
    $response->assertRedirectContains('/members?shown=');
    $page = (string) $this->get((string) $response->headers->get('Location'))->getContent();
    expect(preg_match('/id="member-password" value="([^"]+)"/', $page, $match))->toBe(1);
    $shown = $match[1];
    $advisor = User::query()->where('username', 'kanzlei')->firstOrFail();

    expect(strlen($shown))->toBeGreaterThanOrEqual(20)
        ->and($advisor->memberships()->firstOrFail()->role)->toBe(Role::Accountant)
        ->and($advisor->memberships()->firstOrFail()->org_id)->toBe($this->ownership->org_id);

    $this->get('/members')->assertDontSee($shown);
    $this->get('/members?shown=falsch')->assertDontSee($shown);

    $this->post('/logout');
    $this->post('/login', ['username' => 'kanzlei', 'password' => $shown])->assertRedirect('/');
    $this->assertAuthenticatedAs($advisor);
});

it('needs a fresh confirmation and is closed to everyone else', function (): void {
    session()->forget(RecentConfirmation::KEY);
    $this->actingAs($this->owner)->from('/members')
        ->post('/members', ['display_name' => 'X', 'username' => 'x', 'role' => 'owner'])
        ->assertRedirect('/members')->assertSessionHas('confirm_required');
    expect(User::query()->where('username', 'x')->exists())->toBeFalse();

    $member = Membership::factory()->create(['org_id' => $this->ownership->org_id]);
    $this->actingAs($member->user)->get('/members')->assertForbidden();
});

it('needs a known role and does not touch other organisations', function (): void {
    $this->actingAs($this->owner)
        ->post('/members', ['display_name' => 'X', 'username' => 'x', 'role' => 'member'])
        ->assertSessionHasErrors('role');

    $foreign = Membership::factory()->create();
    $this->post('/members/'.$foreign->id.'/revoke', [])->assertNotFound();
});

it('removes access at once, ends sessions and protects the own account', function (): void {
    $member = Membership::factory()->create(['org_id' => $this->ownership->org_id]);
    DB::table('sessions')->insert(['id' => 'member-session', 'user_id' => $member->user_id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($this->owner)->post('/members/'.$member->id.'/revoke', [])->assertRedirect();

    expect($member->fresh()?->isActive())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'member-session')->exists())->toBeFalse();

    $this->actingAs($member->user)->get('/')->assertForbidden();

    $this->actingAs($this->owner)->post('/members/'.$this->ownership->id.'/revoke', [])->assertForbidden();
});
