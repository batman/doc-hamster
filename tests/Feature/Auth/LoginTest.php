<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an owner and lets them sign in and out', function (): void {
    $this->artisan('dochamster:owner', ['--org' => 'Muster GmbH', '--username' => 'Max', '--password' => 'correct-horse-battery'])
        ->assertSuccessful();

    $this->post('/login', ['username' => 'max', 'password' => 'wrong-password-123'])->assertSessionHasErrors('username');
    $this->assertGuest();

    $this->post('/login', ['username' => 'max', 'password' => 'correct-horse-battery'])->assertRedirect('/');
    $this->assertAuthenticatedAs(User::query()->where('username', 'max')->firstOrFail());

    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});
