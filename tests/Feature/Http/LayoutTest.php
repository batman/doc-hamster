<?php

declare(strict_types=1);

use App\Domain\Auth\User;

/*
| P1-3: shared layout, error pages, UI language.
*/

it('shows error pages in German with the shared layout', function (): void {
    $this->get('/does-not-exist')
        ->assertNotFound()
        ->assertSee('Seite nicht gefunden')
        ->assertSee('css/app.css');
});

it('uses the signed-in user’s language if supported, else German', function (string $locale, string $expected): void {
    $this->actingAs(User::factory()->make(['locale' => $locale]))
        ->get('/')
        ->assertSee($expected);
})->with([
    'English' => ['en', 'Skip to content'],
    'unsupported falls back' => ['fr', 'Zum Inhalt springen'],
]);
