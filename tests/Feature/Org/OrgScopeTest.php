<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Invitation;
use App\Domain\Org\Membership;
use App\Domain\Org\MissingOrgContext;
use App\Domain\Org\OrgScope;
use App\Domain\Org\Permission;
use App\Domain\Org\Role;
use App\Http\Middleware\ResolveCurrentOrg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Support\ScopedInvitation;

/*
| P1-6: active organisation from the session (D-008), org scope, Gate (PLAN §2.1).
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Route::middleware(['web', 'org'])->get('/_test/org', fn () => [
        'org' => app(CurrentOrg::class)->id(),
        'role' => app(CurrentOrg::class)->role()->value,
    ]);
});

function scopedRow(string $orgId): ScopedInvitation
{
    return ScopedInvitation::withoutGlobalScope(OrgScope::class)->create([
        'org_id' => $orgId,
        'role' => Role::Member->value,
        'token_hash' => hash('sha256', (string) random_int(0, PHP_INT_MAX)),
        'expires_at' => now()->addDay(),
    ]);
}

it('uses the active organisation from the session if the user is a member there', function (): void {
    $first = Membership::factory()->owner()->create();
    $second = Membership::factory()->create(['user_id' => $first->user_id]);

    $this->actingAs($first->user)
        ->withSession([ResolveCurrentOrg::SESSION_KEY => $second->org_id])
        ->getJson('/_test/org')
        ->assertOk()
        ->assertExactJson(['org' => $second->org_id, 'role' => 'member']);
});

it('ignores a foreign or revoked organisation in the session and falls back to an own one', function (): void {
    $own = Membership::factory()->owner()->create();
    $foreign = Membership::factory()->create();
    $revoked = Membership::factory()->create(['user_id' => $own->user_id, 'revoked_at' => now()]);

    foreach ([$foreign->org_id, $revoked->org_id] as $sessionOrg) {
        $this->actingAs($own->user)
            ->withSession([ResolveCurrentOrg::SESSION_KEY => $sessionOrg])
            ->getJson('/_test/org')
            ->assertOk()
            ->assertJson(['org' => $own->org_id])
            ->assertSessionHas(ResolveCurrentOrg::SESSION_KEY, $own->org_id);
    }
});

it('refuses users without any active membership', function (): void {
    $user = User::factory()->create();
    Membership::factory()->create(['user_id' => $user->id, 'revoked_at' => now()]);

    $this->actingAs($user)->getJson('/_test/org')->assertForbidden();
});

it('refuses guests', function (): void {
    $this->getJson('/_test/org')->assertUnauthorized();
});

it('reads only rows of the active organisation and fails closed without one', function (): void {
    $mine = Membership::factory()->create();
    $other = Membership::factory()->create();
    $myRow = scopedRow($mine->org_id);
    scopedRow($other->org_id);

    expect(fn () => ScopedInvitation::count())->toThrow(MissingOrgContext::class);

    app(CurrentOrg::class)->set($mine);

    expect(ScopedInvitation::pluck('id')->all())->toBe([$myRow->id])
        ->and(ScopedInvitation::withoutGlobalScope(OrgScope::class)->count())->toBe(2);
});

it('gives new rows the active organisation and refuses writes into another one', function (): void {
    $mine = Membership::factory()->create();
    $other = Membership::factory()->create();
    app(CurrentOrg::class)->set($mine);

    $row = ScopedInvitation::create([
        'role' => Role::Member->value,
        'token_hash' => hash('sha256', 'a'),
        'expires_at' => now()->addDay(),
    ]);

    expect($row->org_id)->toBe($mine->org_id)
        ->and(fn () => ScopedInvitation::create([
            'org_id' => $other->org_id,
            'role' => Role::Member->value,
            'token_hash' => hash('sha256', 'b'),
            'expires_at' => now()->addDay(),
        ]))->toThrow(LogicException::class);
});

it('answers the Gate from the role in the active organisation', function (): void {
    $owner = Membership::factory()->owner()->create();
    $member = Membership::factory()->create(['org_id' => $owner->org_id]);

    app(CurrentOrg::class)->set($owner);
    expect(Gate::forUser($owner->user)->allows(Permission::ManageMembers->value))->toBeTrue()
        ->and(Gate::forUser($member->user)->allows(Permission::ManageMembers->value))->toBeFalse();

    app(CurrentOrg::class)->set($member);
    expect(Gate::forUser($member->user)->allows(Permission::UploadDocuments->value))->toBeTrue()
        ->and(Gate::forUser($member->user)->allows(Permission::ManageMembers->value))->toBeFalse()
        ->and(Gate::forUser($member->user)->allows(Permission::EditDocuments->value))->toBeFalse();
});

it('lets members see all documents only when the organisation switches it on', function (): void {
    $member = Membership::factory()->create();
    app(CurrentOrg::class)->set($member);

    expect(Gate::forUser($member->user)->allows(Permission::ViewAllDocuments->value))->toBeFalse();

    $member->organization->update(['settings_json' => ['members_see_all_documents' => true]]);
    $member->unsetRelation('organization');

    expect(Gate::forUser($member->user)->allows(Permission::ViewAllDocuments->value))->toBeTrue();
});

it('lets tax advisors read and export everything, but change nothing (Q-20)', function (): void {
    $advisor = Membership::factory()->accountant()->create();
    app(CurrentOrg::class)->set($advisor);

    $allowed = array_filter(Permission::cases(), fn (Permission $p): bool => Gate::forUser($advisor->user)->allows($p->value));

    expect(array_values($allowed))->toBe([Permission::ViewAllDocuments, Permission::CreateReports, Permission::Export])
        ->and(Invitation::count())->toBe(0); // identity tables stay unscoped
});
