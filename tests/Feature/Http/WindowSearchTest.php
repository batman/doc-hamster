<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/*
| A104: <x-window search="…"> renders a search field in the head that keeps the window's other query fields.
*/

it('renders the search field with the current term and the other query fields', function (): void {
    $html = Blade::render('<x-window title="Ordner" search="Suchen" url="https://example.com/folders?tags%5B%5D=a&q=Hotel"><p>Inhalt</p></x-window>');

    expect($html)
        ->toContain('data-window-search')
        ->toContain('action="https://example.com/folders"')
        ->toContain('name="tags[]" value="a"')
        ->toContain('name="q" value="Hotel"')
        ->toContain('placeholder="Suchen"')
        ->not->toMatch('/data-window-search-clear[^>]*hidden/');
});

it('renders no search field without the prop', function (): void {
    expect(Blade::render('<x-window title="Ordner"><p>Inhalt</p></x-window>'))->not->toContain('data-window-search');
});
