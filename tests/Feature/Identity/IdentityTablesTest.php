<?php

declare(strict_types=1);

use App\Domain\Audit\AppendOnlyViolation;
use App\Domain\Audit\AuditLog;
use App\Domain\Audit\AuditLogEntry;
use App\Domain\Auth\Device;
use App\Domain\Auth\Passkey;
use App\Domain\Auth\User;
use App\Domain\Org\Invitation;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\Role;
use App\Infrastructure\Crypto\IpHasher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
| P1-2: identity & access tables (PLAN §6.1, §6.4). Needs a real MariaDB or
| MySQL (D-003) – CI runs this group on both.
*/
uses(RefreshDatabase::class)->group('db');

it('uses ULIDs as primary keys', function (): void {
    $membership = Membership::factory()->create();

    expect($membership->id)->toMatch('/^[0-9a-z]{26}$/')
        ->and($membership->org_id)->toMatch('/^[0-9a-z]{26}$/')
        ->and($membership->user_id)->toMatch('/^[0-9a-z]{26}$/');
});

it('gives owners and members no password and tax advisors an Argon2id hash (D-012)', function (): void {
    $owner = User::factory()->create();
    $advisor = User::factory()->accountant('ein-langes-test-passwort')->create();

    expect($owner->hasPassword())->toBeFalse()
        ->and($advisor->hasPassword())->toBeTrue()
        ->and(password_get_info((string) $advisor->password_hash)['algoName'])->toBe('argon2id')
        ->and(Hash::check('ein-langes-test-passwort', (string) $advisor->password_hash))->toBeTrue()
        ->and($advisor->getAuthPassword())->toBe($advisor->password_hash)
        ->and($advisor->toArray())->not->toHaveKey('password_hash');
});

it('keeps usernames and e-mail addresses unique but allows many users without them', function (): void {
    User::factory()->count(2)->create(['email' => null]);
    User::factory()->create(['email' => 'max@example.com']);

    expect(fn () => User::factory()->create(['email' => 'max@example.com']))
        ->toThrow(UniqueConstraintViolationException::class);

    User::factory()->accountant()->create(['username' => 'stb-test']);

    expect(fn () => User::factory()->accountant()->create(['username' => 'stb-test']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows one membership per user and organisation', function (): void {
    $membership = Membership::factory()->owner()->create();

    expect($membership->role)->toBe(Role::Owner)
        ->and($membership->isActive())->toBeTrue()
        ->and($membership->organization->memberships)->toHaveCount(1);

    expect(fn () => Membership::factory()->create([
        'org_id' => $membership->org_id,
        'user_id' => $membership->user_id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses invitations for the tax advisor role (D-012)', function (): void {
    expect(fn () => Invitation::factory()->create(['role' => Role::Accountant]))
        ->toThrow(InvalidArgumentException::class);

    expect(Invitation::factory()->create()->role)->toBe(Role::Member);
});

it('stores device and invitation tokens only as unique hashes, hidden from serialisation', function (): void {
    $device = Device::factory()->create();
    $invitation = Invitation::factory()->create();

    expect($device->token_hash)->toHaveLength(64)
        ->and($device->toArray())->not->toHaveKey('token_hash')
        ->and($invitation->toArray())->not->toHaveKey('token_hash');

    expect(fn () => Device::factory()->create(['token_hash' => $device->token_hash]))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => Invitation::factory()->create(['token_hash' => $invitation->token_hash]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('cuts over-long or broken user agents instead of failing the insert', function (): void {
    $device = Device::factory()->create(['user_agent' => str_repeat('Ä', 600)."\xff"]);

    expect(mb_strlen((string) $device->fresh()?->user_agent))->toBe(512)
        ->and(mb_check_encoding((string) $device->fresh()?->user_agent, 'UTF-8'))->toBeTrue();
});

it('keeps passkey credential IDs unique via their hash, even when they are long', function (): void {
    $longId = str_repeat('A', 1364); // 1023 bytes base64url, the WebAuthn maximum
    $passkey = Passkey::factory()->create(['credential_id' => $longId]);

    expect($passkey->credential_hash)->toBe(hash('sha256', $longId))
        ->and($passkey->fresh()?->credential_id)->toBe($longId)
        ->and($passkey->transports)->toBe(['internal']);

    expect(fn () => Passkey::factory()->create(['credential_id' => $longId]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('stores the passkey public key as raw bytes and keeps the hash on partial saves', function (): void {
    $key = random_bytes(77)."\x00\xff";
    $passkey = Passkey::factory()->create(['public_key' => $key]);

    expect($passkey->fresh()?->public_key)->toBe($key)
        ->and($passkey->toArray())->not->toHaveKey('public_key');

    $partial = Passkey::query()->select(['id', 'user_id', 'sign_count'])->findOrFail($passkey->id);
    $partial->sign_count = 5;
    $partial->save();

    expect($passkey->fresh()?->credential_hash)->toBe($passkey->credential_hash)
        ->and($passkey->fresh()?->sign_count)->toBe(5);
});

it('removes memberships, devices, passkeys and sessions with the user, but keeps the audit log', function (): void {
    $membership = Membership::factory()->create();
    $user = $membership->user;
    Device::factory()->for($user)->create();
    Passkey::factory()->for($user)->create();
    DB::table('sessions')->insert(['id' => 'test-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    app(AuditLog::class)->record('auth.login', userId: $user->id);

    $user->delete();

    expect(Membership::count())->toBe(0)
        ->and(Device::count())->toBe(0)
        ->and(Passkey::count())->toBe(0)
        ->and(DB::table('sessions')->count())->toBe(0)
        ->and(AuditLogEntry::where('user_id', $user->id)->count())->toBe(1);
});

it('records audit entries with a hashed IP only', function (): void {
    $membership = Membership::factory()->create();

    $entry = app(AuditLog::class)->record(
        'org.member_added',
        orgId: $membership->org_id,
        userId: $membership->user_id,
        subject: $membership,
        meta: ['role' => 'member'],
        ip: '192.0.2.10',
    );

    $stored = AuditLogEntry::findOrFail($entry->id);

    expect($stored->subject_type)->toBe('memberships')
        ->and($stored->subject_id)->toBe($membership->id)
        ->and($stored->meta_json)->toBe(['role' => 'member'])
        ->and($stored->ip_hash)->toBe(app(IpHasher::class)->hash('192.0.2.10'))
        ->and($stored->ip_hash)->not->toBe(hash('sha256', '192.0.2.10'))
        ->and(json_encode(DB::table('audit_log')->first()))->not->toContain('192.0.2.10');
});

it('does not let audit entries be changed or deleted through the model', function (Closure $attempt): void {
    $entry = app(AuditLog::class)->record('auth.login');

    expect(fn () => $attempt($entry))->toThrow(AppendOnlyViolation::class)
        ->and(AuditLogEntry::findOrFail($entry->id)->action)->toBe('auth.login')
        ->and(AuditLogEntry::count())->toBe(1);
})->with([
    'update' => [fn (AuditLogEntry $e) => $e->update(['action' => 'auth.logout'])],
    'updateQuietly' => [fn (AuditLogEntry $e) => $e->updateQuietly(['action' => 'auth.logout'])],
    'delete' => [fn (AuditLogEntry $e) => $e->delete()],
    'deleteQuietly' => [fn (AuditLogEntry $e) => $e->deleteQuietly()],
    'withoutEvents' => [fn (AuditLogEntry $e) => AuditLogEntry::withoutEvents(fn () => $e->delete())],
]);

it('stores no client IP in sessions', function (): void {
    app()->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '192.0.2.10']));

    app('session')->driver('database')->getHandler()->write('no-ip-session', 'payload');

    $row = DB::table('sessions')->where('id', 'no-ip-session')->first();

    expect($row)->not->toBeNull()
        ->and($row?->ip_address)->toBeNull();
});

it('rejects malformed audit action names', function (string $action): void {
    expect(fn () => app(AuditLog::class)->record($action))->toThrow(InvalidArgumentException::class);
})->with(['login', 'Auth.Login', 'auth.', 'auth.login; drop']);

it('creates organisations with the default trash period and no forced retention', function (): void {
    $org = Organization::factory()->create()->fresh();

    expect($org?->trash_days)->toBe(30)
        ->and($org?->retention_days)->toBeNull();
});
