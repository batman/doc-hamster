<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Devices\DeviceKey;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgRole;
use App\Domain\Org\OrgScope;
use App\Domain\Org\PersonAccess;
use App\Domain\Org\RoleRestriction;
use App\Domain\ReceiptPack\ReceiptPack;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Point 215: role restrictions (period, accounts, folders – all together, A387), no Posteingang with folders (A384),
| receipt packs only when everything in them is allowed (A386); person access dates (218) and the pause (223, A389).
*/
uses(RefreshDatabase::class)->group('db');

const RESTRICT_PASSWORD = 'ein-langes-test-passwort';

beforeEach(function (): void {
    $this->owner = Membership::factory()->owner()->create();
    $this->orgId = $this->owner->org_id;
    app(CurrentOrg::class)->set($this->owner);
    $this->giro = BankAccount::query()->create(['name' => 'Giro', 'currency' => 'EUR']);
    $this->cash = BankAccount::query()->create(['name' => 'Kasse', 'currency' => 'EUR', 'kind' => 'cash']);
    $this->top = Folder::query()->create(['name' => 'Rechnungen']);
    $this->sub = Folder::query()->create(['name' => '2026', 'parent_id' => $this->top->id]);
    $this->other = Folder::query()->create(['name' => 'Privat']);
    $this->role = OrgRole::query()->create(['name' => 'Kanzlei', 'rights' => ['view_all', 'packs', 'banking']]);
    app(CurrentOrg::class)->clear();
    $this->person = Membership::factory()->create(['org_id' => $this->orgId, 'org_role_id' => $this->role->id,
        'user_id' => User::factory()->create(['username' => 'kanzlei', 'password_hash' => RESTRICT_PASSWORD])->id]);
});

function restrictedDoc(string $title, ?string $folder, string $date): Document
{
    return Document::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => test()->orgId, 'uploaded_by' => test()->owner->user_id, 'title' => $title, 'folder_id' => $folder, 'doc_date' => $date]);
}

function booking(BankAccount $account, string $date, ?Document $document = null): Transaction
{
    $booking = Transaction::query()->withoutGlobalScopes()->create(['org_id' => test()->orgId, 'bank_account_id' => $account->id, 'booking_date' => $date, 'amount_minor' => -100,
        'currency' => 'EUR', 'counterparty' => 'Muster', 'purpose' => 'x', 'fingerprint' => Str::random(64)]);
    if ($document !== null) {
        DB::table('matches')->insert(['id' => Str::lower((string) Str::ulid()), 'org_id' => test()->orgId, 'transaction_id' => $booking->id, 'document_id' => $document->id, 'state' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $booking;
}

/** Runs the query as the restricted person of a request would. */
function asRestricted(Closure $run): mixed
{
    $org = app(CurrentOrg::class);
    $org->set(test()->person->fresh() ?? test()->person);
    $org->restrictTo(test()->role->fresh()?->restriction());

    return $run();
}

it('combines period, accounts and folders for documents and bookings (A387)', function (): void {
    $this->role->forceFill(['period_from' => '2026-01-01', 'period_to' => '2026-12-31', 'account_ids' => [$this->giro->id], 'folder_ids' => [$this->top->id]])->save();
    $inSub = restrictedDoc('in Unterordner', $this->sub->id, '2026-03-01');
    restrictedDoc('falscher Ordner', $this->other->id, '2026-03-01');
    restrictedDoc('zu alt', $this->top->id, '2025-12-31');
    restrictedDoc('Posteingang', null, '2026-03-01');
    $cashOnly = restrictedDoc('nur Kasse', $this->top->id, '2026-04-01');
    booking($this->cash, '2026-04-01', $cashOnly);
    $giroDoc = restrictedDoc('Giro', $this->top->id, '2026-05-01');
    booking($this->giro, '2026-05-01', $giroDoc);
    booking($this->giro, '2025-06-01');

    $titles = asRestricted(fn () => Document::query()->visibleTo($this->person->user_id, true)->orderBy('title')->pluck('title')->all());
    $bookings = asRestricted(fn () => Transaction::query()->count());
    $accounts = asRestricted(fn () => BankAccount::query()->pluck('name')->all());

    // F41: with an account restriction a document without a booking is hidden too.
    expect($titles)->toBe(['Giro'])
        ->and($bookings)->toBe(1)
        ->and($accounts)->toBe(['Giro'])
        ->and($inSub->exists)->toBeTrue();

    // Admins and jobs (no restriction set) see everything.
    app(CurrentOrg::class)->set($this->owner);
    expect(Document::query()->visibleTo($this->owner->user_id, true)->count())->toBe(6)
        ->and(Transaction::query()->count())->toBe(3);
});

it('hides the Posteingang from a role restricted to folders (A384) and refuses the files of other folders', function (): void {
    $this->role->forceFill(['folder_ids' => [$this->top->id], 'rights' => ['view_all', 'upload']])->save();
    $this->actingAs($this->person->user);
    $this->get('/inbox')->assertForbidden();

    $this->role->forceFill(['folder_ids' => null])->save();
    $this->get('/inbox')->assertOk();
});

it('shows a receipt pack only when everything in it is allowed (A386)', function (): void {
    $inside = restrictedDoc('drin', $this->top->id, '2026-02-01');
    $outside = restrictedDoc('draußen', $this->other->id, '2026-02-02');
    booking($this->giro, '2026-02-01', $inside);
    $pack = fn (array $docs, array $accounts = []) => new ReceiptPack(['period_from' => '2026-01-01', 'period_to' => '2026-03-31', 'account_ids' => $accounts ?: [$this->giro->id], 'summary' => ['documents' => $docs]]);

    $restriction = new RoleRestriction(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), [$this->giro->id], [$this->top->id]);
    app(CurrentOrg::class)->set($this->owner);

    expect($restriction->allowsPack($pack([$inside->id])))->toBeTrue()
        ->and($restriction->allowsPack($pack([$inside->id, $outside->id])))->toBeFalse()
        ->and($restriction->allowsPack($pack([$inside->id], [$this->giro->id, $this->cash->id])))->toBeFalse()
        ->and((new RoleRestriction(CarbonImmutable::parse('2026-02-01')))->allowsPack($pack([])))->toBeFalse()
        ->and((new RoleRestriction)->allowsPack($pack([$outside->id])))->toBeTrue();
});

it('refuses sign-in before and after "Zugang von / bis", also in the middle of a session (218)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00'));
    $this->person->update(['access_from' => '2026-03-15', 'access_until' => '2026-05-31']);

    $this->post('/login', ['username' => 'kanzlei', 'password' => RESTRICT_PASSWORD])->assertSessionHasErrors(['username' => 'Zugang ab 15.03.2026.']);
    $this->assertGuest();

    $this->travelTo(CarbonImmutable::parse('2026-05-31 12:00'));
    $this->post('/login', ['username' => 'kanzlei', 'password' => RESTRICT_PASSWORD]);
    $this->assertAuthenticatedAs($this->person->user);
    $this->get('/documents')->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-06-01 08:00'));
    $this->get('/documents')->assertRedirect('/login');
    $this->assertGuest();
    expect(PersonAccess::refusal($this->person->fresh()))->toBe('Ihr Zugang ist am 31.05.2026 abgelaufen.');
});

it('pauses a role or a person: signed out at once, sign-in and upload keys refused, all back on resume (223)', function (): void {
    $this->role->forceFill(['rights' => ['upload', 'view_all']])->save();
    app(CurrentOrg::class)->set($this->owner);
    [, $key] = DeviceKey::issue($this->person->user_id, 'Handy', 'phone');
    app(CurrentOrg::class)->clear();
    DB::table('sessions')->insert(['id' => 'sitzung-1', 'user_id' => $this->person->user_id, 'payload' => '', 'last_activity' => time()]);
    $admin = User::query()->findOrFail($this->owner->user_id);
    $admin->forceFill(['password_hash' => RESTRICT_PASSWORD])->save();

    $this->actingAs($admin);
    $this->confirmed();
    $this->postJson('/roles/'.$this->role->id.'/pause', ['paused' => '1'])->assertOk();
    expect(DB::table('sessions')->where('user_id', $this->person->user_id)->exists())->toBeFalse();

    $this->post('/logout');
    $this->post('/login', ['username' => 'kanzlei', 'password' => RESTRICT_PASSWORD])->assertSessionHasErrors(['username' => 'Ihr Zugang ist vorübergehend pausiert.']);
    $this->withToken($key)->post('/api/upload', ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertForbidden();

    $this->role->forceFill(['paused_at' => null])->save();
    $this->person->update(['paused_at' => now()]);
    $this->withToken($key)->getJson('/api/upload')->assertForbidden();

    $this->person->update(['paused_at' => null]);
    $this->withToken($key)->getJson('/api/upload')->assertOk();
    $this->post('/login', ['username' => 'kanzlei', 'password' => RESTRICT_PASSWORD]);
    $this->assertAuthenticatedAs($this->person->user);
});

it('turns advisor accesses and accountant memberships into persons with a "Steuerberater" role', function (): void {
    $migration = require database_path('migrations/2026_11_19_110000_roles_with_restrictions_and_person_access.php');
    $org = $this->orgId;
    $old = OrgRole::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $org)->where('name', 'Steuerberater')->firstOrFail();
    // The state before the update: the pre-installed accountant role, two accountants, one with an access row.
    DB::table('roles')->where('id', $old->id)->update(['system_key' => 'accountant', 'rights' => json_encode(['view_all', 'export'])]);
    $plain = Membership::factory()->create(['org_id' => $org, 'org_role_id' => $old->id]);
    $scoped = Membership::factory()->create(['org_id' => $org, 'org_role_id' => $old->id]);
    DB::table('memberships')->whereIn('id', [$plain->id, $scoped->id])->update(['role' => 'accountant']);

    $migration->convert([[
        'org_id' => $org, 'membership_id' => $scoped->id, 'user_id' => $scoped->user_id, 'period_from' => '2026-01-01', 'period_to' => '2026-12-31',
        'account_ids' => json_encode([$this->giro->id]), 'expires_at' => '2027-03-31', 'must_change_password' => 1,
    ]]);

    $base = OrgRole::query()->withoutGlobalScope(OrgScope::class)->findOrFail($old->id);
    $scopedRole = OrgRole::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $org)->where('name', 'Steuerberater 2')->firstOrFail();
    $scoped = $scoped->fresh();
    expect(DB::table('memberships')->whereIn('id', [$plain->id, $scoped?->id])->pluck('role')->unique()->all())->toBe(['member'])
        ->and($base->system_key)->toBeNull()
        ->and($base->rights)->toBe(['view_all', 'export', 'packs'])
        ->and($plain->fresh()?->org_role_id)->toBe($base->id)
        ->and($scoped?->org_role_id)->toBe($scopedRole->id)
        ->and($scopedRole->period_from?->toDateString())->toBe('2026-01-01')
        ->and($scopedRole->account_ids)->toBe([$this->giro->id])
        ->and($scoped?->access_until?->toDateString())->toBe('2027-03-31')
        ->and(User::query()->findOrFail($scoped?->user_id)->must_change_password)->toBeTrue();
});

it('draws the Rollen and Personen pages and the restricted person\'s Belegpakete', function (): void {
    $this->role->forceFill(['period_from' => '2026-01-01', 'folder_ids' => [$this->sub->id], 'paused_at' => null])->save();
    $this->person->update(['access_until' => '2026-12-31']);
    $this->actingAs(User::query()->findOrFail($this->owner->user_id));
    $this->get('/roles?open='.$this->role->id)->assertOk()->assertSee('Kanzlei')->assertSee('ab 01.01.2026')->assertSee('1 Ordner');
    $this->get('/members?open='.$this->person->id)->assertOk()->assertSee('kanzlei')->assertSee('bis 31.12.');

    $this->actingAs($this->person->user)->get('/export/packs')->assertOk()->assertDontSee(__('receipt_pack.create_title'));
});

it('keeps a private folder for the owner only – no role, no bank matching, no pack (A69–A71)', function (): void {
    app(CurrentOrg::class)->set($this->owner);
    $this->other->forceFill(['is_private' => true, 'bank_matching' => true])->save();
    $this->top->forceFill(['bank_matching' => true])->save();
    $inner = Folder::query()->create(['name' => 'Ausweis', 'parent_id' => $this->other->id]);
    restrictedDoc('Ausweis', $inner->id, '2026-03-01');
    restrictedDoc('Rechnung', $this->top->id, '2026-03-01');
    app(CurrentOrg::class)->clear();

    // A role without any restriction (all folders ticked) still never sees it.
    $titles = asRestricted(fn () => Document::query()->visibleTo($this->person->user_id, true)->orderBy('title')->pluck('title')->all());
    $folders = asRestricted(fn () => Folder::query()->pluck('name')->all());
    expect($titles)->toBe(['Rechnung'])->and($folders)->not->toContain('Privat')->not->toContain('Ausweis');

    app(CurrentOrg::class)->set($this->owner);
    expect(Document::query()->visibleTo($this->owner->user_id, true)->count())->toBe(2)
        ->and(Document::query()->matchable()->pluck('title')->all())->toBe(['Rechnung'])
        ->and(Document::query()->notPrivate()->pluck('title')->all())->toBe(['Rechnung']);
});
