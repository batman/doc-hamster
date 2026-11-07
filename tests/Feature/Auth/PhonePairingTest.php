<?php

declare(strict_types=1);

use App\Domain\Auth\Device;
use App\Domain\Auth\DevicePairing;
use App\Domain\Org\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

/*
| Connect the phone once (PLAN §11.1, issue #37): a signed-in person shows a
| code, the phone uses it once and from then on stays signed in by its device
| cookie, also after the short session has ended.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->owner = Membership::factory()->owner()->create();
});

/** Shows a code as the owner and returns it in plain text. */
function pairingCode(object $test, Membership $member): string
{
    $test->actingAs($member->user)->post('/phone/code')->assertRedirect('/phone');

    return (string) session('pairing_code');
}

/** Connects "the phone" with the code (as a guest) and returns its device cookie. */
function connectPhone(object $test, string $code): string
{
    Auth::logout();
    $test->flushSession();
    $response = $test->post('/connect', ['code' => $code, 'name' => 'Testhandy'])->assertRedirect('/phone');
    $cookie = $response->getCookie('__Host-dh_device');
    expect($cookie)->not->toBeNull();
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(170)->getTimestamp());

    return (string) $cookie->getValue();
}

it('shows a QR code and a short code once', function (): void {
    $code = pairingCode($this, $this->owner);

    expect(DevicePairing::normalise($code))->toHaveLength(8);
    $this->get('/phone')->assertOk()->assertSee(DevicePairing::format($code))->assertSee('data:image/svg+xml;base64,', false);
    // Flash only: a reload does not show it again, and only the hash is stored.
    $this->get('/phone')->assertOk()->assertDontSee(DevicePairing::format($code));
    expect(DevicePairing::query()->first()?->code_hash)->not->toContain($code);
});

it('opening the link alone does not use the code up', function (): void {
    $code = pairingCode($this, $this->owner);

    $this->get('/connect/'.DevicePairing::format($code))->assertOk()->assertSee(DevicePairing::format($code));
    expect(DevicePairing::query()->whereNull('used_at')->count())->toBe(1);
});

it('connects the phone once and keeps it signed in after the session ended', function (): void {
    $code = pairingCode($this, $this->owner);
    $token = connectPhone($this, strtolower($code));

    $this->assertAuthenticatedAs($this->owner->user);
    expect(Device::query()->where('user_id', $this->owner->user_id)->count())->toBe(1);

    // The session is gone (phone closed for days): the cookie signs in again by itself.
    Auth::logout();
    $this->flushSession();
    $this->withCookie('__Host-dh_device', $token)->get('/')->assertOk();
    $this->assertAuthenticatedAs($this->owner->user);
});

it('uses a code only once and not after 10 minutes', function (): void {
    $code = pairingCode($this, $this->owner);
    connectPhone($this, $code);

    Auth::logout();
    $this->flushSession();
    $this->post('/connect', ['code' => $code])->assertSessionHasErrors('code');

    $late = pairingCode($this, $this->owner);
    $this->travel(11)->minutes();
    Auth::logout();
    $this->flushSession();
    $this->post('/connect', ['code' => $late])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('does not sign in with an unknown or removed device cookie', function (): void {
    $this->withCookie('__Host-dh_device', str_repeat('a', 64))->get('/')->assertRedirect('/login');
    $this->assertGuest();

    $token = connectPhone($this, pairingCode($this, $this->owner));
    Device::query()->update(['revoked_at' => now()]);
    Auth::logout();
    $this->flushSession();
    $this->withCookie('__Host-dh_device', $token)->get('/')->assertRedirect('/login');
    $this->assertGuest();
});

it('ends the phone session at once when the phone is removed', function (): void {
    connectPhone($this, pairingCode($this, $this->owner));
    $device = Device::query()->firstOrFail();

    // Still signed in on the phone with an open session …
    $this->get('/')->assertOk();
    $device->forceFill(['revoked_at' => now()])->save();
    // … which ends with the next click.
    $this->get('/')->assertRedirect('/login');
    $this->assertGuest();
});

it('lists the own phones and removes one', function (): void {
    connectPhone($this, pairingCode($this, $this->owner));
    $device = Device::query()->firstOrFail();
    $other = Device::factory()->create();

    $this->actingAs($this->owner->user)->get('/phone')->assertOk()->assertSee('Testhandy');
    $this->post('/phone/devices/'.$other->id.'/revoke')->assertNotFound();
    $this->post('/phone/devices/'.$device->id.'/revoke')->assertRedirect('/phone');
    expect($device->fresh()?->revoked_at)->not->toBeNull();
});

it('signing out on the phone ends its device login', function (): void {
    $token = connectPhone($this, pairingCode($this, $this->owner));

    $this->post('/logout')->assertRedirect('/login');
    expect(Device::query()->firstOrFail()->revoked_at)->not->toBeNull();
    $this->flushSession();
    $this->withCookie('__Host-dh_device', $token)->get('/')->assertRedirect('/login');
});

it('gives tax advisors no device login', function (): void {
    $advisor = Membership::factory()->accountant()->create(['org_id' => $this->owner->org_id]);

    $this->actingAs($advisor->user)->post('/phone/code')->assertForbidden();
    $this->get('/phone')->assertOk()->assertSee(__('phone.not_for_advisor'))->assertDontSee(route('phone.start'));
});

it('points to connecting the phone on home until one is connected', function (): void {
    $this->actingAs($this->owner->user)->get('/')->assertOk()->assertSee(__('phone.home_hint'));

    connectPhone($this, pairingCode($this, $this->owner));
    $this->get('/')->assertOk()->assertDontSee(__('phone.home_hint'));
});
