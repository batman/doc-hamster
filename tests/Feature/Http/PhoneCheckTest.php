<?php

declare(strict_types=1);

use App\Http\Controllers\PhoneCheckController;

it('opens the phone probe without login', function (): void {
    $this->get('/phone-check')->assertOk()->assertSee('rel="manifest"', false);
});

it('offers a home-screen manifest', function (): void {
    $this->get('/phone-check/manifest')->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('display', 'standalone')
        ->assertJsonPath('start_url', '/phone-check');
});

it('keeps the QR page behind the login', function (): void {
    $this->get('/phone-check/qr')->assertRedirect('/login');
});

it('renders a page QR and a test QR', function (): void {
    $html = app(PhoneCheckController::class)->qr()->render();

    expect(substr_count($html, 'data:image/svg+xml;base64,'))->toBe(2);
});
