<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\Argon2IdHasher;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

uses(RefreshDatabase::class);

final class CountingHasher extends Argon2IdHasher
{
    public int $checks = 0;

    public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        $this->checks++;

        return parent::check($value, $hashedValue, $options);
    }
}

function countingHasher(): CountingHasher
{
    $hasher = new CountingHasher;
    Hash::extend('argon2id', fn (): CountingHasher => $hasher);
    Hash::forgetDrivers();

    return $hasher;
}

// Note: there is deliberately no wall-clock timing assertion below. The
// in-process test overhead (~500 ms per request) dwarfs the ~145 ms hash, so
// a timing bound passes with and without the fix (verified empirically) and
// would only fake confidence. The counting-hasher tests prove one Argon2id
// verify runs on every failure path; equal cost in production follows by
// construction (same hasher, same options, one verify each side).
it('verifies a password hash even when the username does not exist', function (): void {
    $hasher = countingHasher();

    $this->post('/login', ['username' => 'no-such-user', 'password' => 'wrong-password-123'])
        ->assertRedirect()
        ->assertSessionHasErrors('username');
    $this->assertGuest();

    expect($hasher->checks)->toBe(1);
});

it('fails identically for an unknown username and a wrong password', function (): void {
    User::factory()->accountant('correct-horse-battery')->create(['username' => 'stb-example']);

    $unknown = $this->post('/login', ['username' => 'no-such-user', 'password' => 'correct-horse-battery']);
    $wrong = $this->post('/login', ['username' => 'stb-example', 'password' => 'wrong-password-123']);

    foreach ([$unknown, $wrong] as $response) {
        $response->assertRedirect()->assertSessionHasErrors(['username' => __('auth.failed')]);
    }

    expect($unknown->getStatusCode())->toBe($wrong->getStatusCode())
        ->and($unknown->headers->get('Location'))->toBe($wrong->headers->get('Location'));
});

it('verifies a hash for users without a password, too', function (): void {
    $hasher = countingHasher();
    User::factory()->create(['username' => 'passkey-only', 'password_hash' => null]);

    $this->post('/login', ['username' => 'passkey-only', 'password' => 'any-password-123'])
        ->assertRedirect()
        ->assertSessionHasErrors('username');
    $this->assertGuest();

    expect($hasher->checks)->toBe(1);
});

it('rejects an overlong password for an unknown user without error', function (): void {
    $hasher = countingHasher();

    $this->post('/login', ['username' => 'no-such-user', 'password' => str_repeat('p', 10000)])
        ->assertRedirect()
        ->assertSessionHasErrors('username');
    $this->assertGuest();

    expect($hasher->checks)->toBe(1);
});
