<?php

declare(strict_types=1);

use App\Domain\Org\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| THEME: the layout wires the day/night switch and the self-hosted fonts.
*/
uses(RefreshDatabase::class)->group('db');

it('offers guests the theme switch with local fonts only', function (): void {
    $this->get('/login')->assertOk()
        ->assertSee('data-theme-toggle', false)
        ->assertSee('fonts/jetbrains-mono-normal-latin.woff2', false)
        ->assertSee('fonts/fraunces-italic-latin.woff2', false)
        ->assertSee('name="theme-color"', false)
        ->assertDontSee('googleapis')
        ->assertDontSee('gstatic');
});

it('offers members the theme switch in the sidebar', function (): void {
    $admin = Membership::factory()->owner()->create();

    $this->actingAs($admin->user)->get('/')->assertOk()
        ->assertSee('nav-theme', false)
        ->assertSee('theme-dark', false)
        ->assertSee('theme-light', false);
});
