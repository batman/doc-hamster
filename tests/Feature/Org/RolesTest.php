<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgRole;
use App\Domain\Org\OrgScope;
use App\Domain\Org\Permission;
use App\Domain\Org\Role;
use App\Domain\Org\RoleRight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/*
| #30: roles the Admin creates, permissions as checkboxes.
*/
uses(RefreshDatabase::class)->group('db');

const ADMIN_PASSWORD = 'ein-langes-test-passwort';

beforeEach(function (): void {
    $this->admin = User::factory()->create(['password_hash' => ADMIN_PASSWORD]);
    $this->adminship = Membership::factory()->owner()->create(['user_id' => $this->admin->id]);
});

function roleOf(string $orgId, Role $key): OrgRole
{
    return OrgRole::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)->where('system_key', $key->value)->firstOrFail();
}

it('gives a new organisation the two pre-installed roles with the agreed checkboxes', function (): void {
    $org = Organization::factory()->create();

    $employee = roleOf($org->id, Role::Member);
    $advisor = roleOf($org->id, Role::Accountant);

    expect($employee->name)->toBe('Mitarbeiter')
        ->and($employee->rights)->toBe(array_column(RoleRight::cases(), 'value'))
        ->and($advisor->name)->toBe('Steuerberater')
        ->and($advisor->rights)->toBe(['view_all', 'export']);
});

it('answers the Gate from the checkboxes, and Admins may everything', function (): void {
    $member = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->adminship->org_id]);

    app(CurrentOrg::class)->set($member);
    $allowed = array_filter(Permission::cases(), fn (Permission $p): bool => Gate::forUser($member->user)->allows($p->value));
    expect(array_values($allowed))->toBe([Permission::UploadDocuments, Permission::ManageOwnDocuments]);

    app(CurrentOrg::class)->set($this->adminship);
    foreach (Permission::cases() as $permission) {
        expect(Gate::forUser($this->admin)->allows($permission->value))->toBeTrue();
    }
});

it('gives nobody but Admins people, roles and updates, whatever the checkboxes say', function (): void {
    $member = Membership::factory()->create(['org_id' => $this->adminship->org_id]);

    $this->actingAs($member->user);
    $this->get('/members')->assertForbidden();
    $this->get('/roles')->assertForbidden();
    $this->get('/update')->assertForbidden();
    $this->post('/roles', ['name' => 'Chef', 'rights' => ['upload'], 'password' => 'x'])->assertForbidden();
});

it('lets an Admin create a role and give it to a new person', function (): void {
    $this->actingAs($this->admin)
        ->post('/roles', ['name' => 'Aushilfe', 'rights' => ['upload', 'tags'], 'password' => ADMIN_PASSWORD])
        ->assertRedirect(route('roles.index'));

    $role = OrgRole::query()->withoutGlobalScope(OrgScope::class)->where('name', 'Aushilfe')->firstOrFail();

    expect($role->org_id)->toBe($this->adminship->org_id)
        ->and($role->rights)->toBe(['upload', 'tags'])
        ->and($role->isPreinstalled())->toBeFalse();

    $this->post('/members', ['display_name' => 'Max', 'username' => 'max', 'role' => $role->id, 'password' => ADMIN_PASSWORD])->assertRedirect();

    $membership = User::query()->where('username', 'max')->firstOrFail()->memberships()->firstOrFail();

    expect($membership->org_role_id)->toBe($role->id)
        ->and($membership->role)->toBe(Role::Member);
    $this->get('/members')->assertSee('Aushilfe');
});

it('refuses taken names in any case, the Admin name and unknown checkboxes', function (): void {
    $this->actingAs($this->admin);

    foreach (['mitarbeiter', 'ADMIN'] as $name) {
        $this->post('/roles', ['name' => $name, 'password' => ADMIN_PASSWORD])->assertSessionHasErrors('name');
    }

    $this->post('/roles', ['name' => 'Neu', 'rights' => ['members.manage'], 'password' => ADMIN_PASSWORD])->assertSessionHasErrors('rights.0');
    $this->post('/roles', ['name' => 'Neu', 'password' => 'falsch'])->assertSessionHasErrors('password');
});

it('changes name and checkboxes of a pre-installed role, but never deletes it', function (): void {
    $role = roleOf($this->adminship->org_id, Role::Accountant);

    $this->actingAs($this->admin)
        ->post('/roles/'.$role->id, ['name' => 'Kanzlei', 'rights' => ['view_all'], 'password' => ADMIN_PASSWORD])
        ->assertRedirect();

    expect($role->fresh()?->name)->toBe('Kanzlei')
        ->and($role->fresh()?->rights)->toBe(['view_all']);

    $this->post('/roles/'.$role->id.'/delete', ['password' => ADMIN_PASSWORD])->assertForbidden();
    expect($role->fresh())->not->toBeNull();
});

it('deletes an own role only while nobody active has it', function (): void {
    $this->actingAs($this->admin);
    $this->post('/roles', ['name' => 'Aushilfe', 'password' => ADMIN_PASSWORD]);
    $role = OrgRole::query()->withoutGlobalScope(OrgScope::class)->where('name', 'Aushilfe')->firstOrFail();
    $member = Membership::factory()->create(['org_id' => $this->adminship->org_id, 'org_role_id' => $role->id]);

    $this->post('/roles/'.$role->id.'/delete', ['password' => ADMIN_PASSWORD])->assertSessionHasErrors('role');
    expect($role->fresh())->not->toBeNull();

    $member->update(['revoked_at' => now()]);
    $this->post('/roles/'.$role->id.'/delete', ['password' => ADMIN_PASSWORD])->assertRedirect(route('roles.index'));

    expect($role->fresh())->toBeNull()
        ->and($member->fresh()?->org_role_id)->toBeNull();
});

it('changes the role of a person, and the new rights apply at once', function (): void {
    $member = Membership::factory()->create(['org_id' => $this->adminship->org_id]);
    $advisorRole = roleOf($this->adminship->org_id, Role::Accountant);

    $this->actingAs($this->admin)
        ->post('/members/'.$member->id.'/role', ['role' => $advisorRole->id, 'password' => ADMIN_PASSWORD])
        ->assertRedirect(route('members.index'));

    expect($member->fresh()?->role)->toBe(Role::Accountant)
        ->and($member->fresh()?->org_role_id)->toBe($advisorRole->id);

    $this->actingAs($member->user)->post('/documents', ['file' => UploadedFile::fake()->image('bon.jpg')])->assertForbidden();
});

it('allows several Admins and always keeps at least one', function (): void {
    $member = Membership::factory()->create(['org_id' => $this->adminship->org_id]);

    $this->actingAs($this->admin)
        ->post('/members/'.$member->id.'/role', ['role' => 'owner', 'password' => ADMIN_PASSWORD])
        ->assertRedirect();

    expect($member->fresh()?->role)->toBe(Role::Owner)
        ->and($member->fresh()?->org_role_id)->toBeNull();

    // The second Admin takes the first one down; then that one is the last and is protected.
    $second = User::query()->whereKey($member->user_id)->firstOrFail();
    $second->update(['password_hash' => ADMIN_PASSWORD]);
    $employeeRole = roleOf($this->adminship->org_id, Role::Member);

    $this->actingAs($second)
        ->post('/members/'.$this->adminship->id.'/role', ['role' => $employeeRole->id, 'password' => ADMIN_PASSWORD])
        ->assertRedirect(route('members.index'));

    expect($this->adminship->fresh()?->role)->toBe(Role::Member);

    $this->actingAs($second)
        ->post('/members/'.$member->id.'/role', ['role' => $employeeRole->id, 'password' => ADMIN_PASSWORD])
        ->assertForbidden();
    $this->post('/members/'.$member->id.'/revoke', ['password' => ADMIN_PASSWORD])->assertForbidden();
});

it('refuses roles of other organisations', function (): void {
    $member = Membership::factory()->create(['org_id' => $this->adminship->org_id]);
    $foreign = roleOf(Organization::factory()->create()->id, Role::Member);

    $this->actingAs($this->admin);
    $this->post('/members/'.$member->id.'/role', ['role' => $foreign->id, 'password' => ADMIN_PASSWORD])->assertSessionHasErrors('role');
    $this->post('/roles/'.$foreign->id, ['name' => 'X', 'password' => ADMIN_PASSWORD])->assertNotFound();
    $this->post('/roles/'.$foreign->id.'/delete', ['password' => ADMIN_PASSWORD])->assertNotFound();
});

it('exports only the own receipts without the checkbox "Alle Belege sehen"', function (): void {
    Storage::fake('local');
    $orgId = $this->adminship->org_id;
    $this->actingAs($this->admin)->post('/documents', ['file' => UploadedFile::fake()->image('fremd.jpg')]);

    $user = User::factory()->create(['password_hash' => ADMIN_PASSWORD]);
    Membership::factory()->withRights(RoleRight::Upload, RoleRight::Export)->create(['org_id' => $orgId, 'user_id' => $user->id]);
    $this->actingAs($user)->post('/documents', ['file' => UploadedFile::fake()->image('eigen.jpg')]);

    $today = today()->format('Y-m-d');
    $path = $this->post('/export', ['from' => $today, 'to' => $today, 'password' => ADMIN_PASSWORD])
        ->assertOk()->baseResponse->getFile()->getPathname();

    $zip = new ZipArchive;
    $zip->open($path);
    $index = (string) $zip->getFromName('index.csv');
    $zip->close();
    unlink($path);

    expect(Document::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(2)
        ->and($index)->toContain('eigen.jpg')
        ->and($index)->not->toContain('fremd.jpg');
});

it('keeps the rights of people who exist before the update', function (): void {
    $migration = require database_path('migrations/2026_10_31_100000_create_roles_table.php');
    $seesAll = Organization::factory()->create(['settings_json' => ['members_see_all_documents' => true, 'members_may_trash_own_documents' => false]]);
    $member = Membership::factory()->create();
    $advisor = Membership::factory()->accountant()->create(['org_id' => $member->org_id]);
    $owner = Membership::factory()->owner()->create(['org_id' => $member->org_id]);

    // Only the data step: schema changes would end the test's transaction on MariaDB/MySQL.
    DB::table('memberships')->update(['org_role_id' => null]);
    DB::table('roles')->delete();
    $migration->addRolesToExistingOrganizations();

    $member = $member->fresh();
    expect($member?->orgRole?->rights)->toBe(['upload', 'edit', 'delete'])
        ->and($advisor->fresh()?->orgRole?->rights)->toBe(['view_all', 'export'])
        ->and($owner->fresh()?->org_role_id)->toBeNull()
        ->and(roleOf($seesAll->id, Role::Member)->rights)->toBe(['upload', 'edit', 'view_all'])
        ->and(DB::table('roles')->where('org_id', $member?->org_id)->count())->toBe(2);
});
