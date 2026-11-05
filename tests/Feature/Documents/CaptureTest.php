<?php

declare(strict_types=1);

use App\Domain\Org\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Browser capture page: live camera plus a file fallback, posting to documents.store.
*/
uses(RefreshDatabase::class)->group('db');

it('sends guests to the login', function (): void {
    $this->get('/capture')->assertRedirect(route('login'));
});

it('shows the capture page to anyone who may upload', function (): void {
    $owner = Membership::factory()->owner()->create();

    $this->actingAs($owner->user)->get('/capture')->assertOk()
        ->assertSee('capture-preview', false)
        ->assertSee('name="file"', false)
        ->assertSee(route('documents.store'), false)
        ->assertSee('js/capture.js', false);
});

it('forbids the capture page without the upload right', function (): void {
    $advisor = Membership::factory()->accountant()->create();

    $this->actingAs($advisor->user)->get('/capture')->assertForbidden();
});
