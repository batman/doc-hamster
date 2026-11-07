<?php

declare(strict_types=1);

use App\Domain\Notes\NoteText;

// Lists written inline in a note (Go of 2026-11-07).
it('reads lists anywhere in the text and ticks one entry', function (): void {
    $body = "Einkauf:\n[Milch, #Brot]\ndann [a, b] und [] bleibt";
    expect(NoteText::parse($body))->toBe([
        ['type' => 'text', 'text' => 'Einkauf:'],
        ['type' => 'list', 'items' => [['text' => 'Milch', 'done' => false], ['text' => 'Brot', 'done' => true]]],
        ['type' => 'text', 'text' => 'dann '],
        ['type' => 'list', 'items' => [['text' => 'a', 'done' => false], ['text' => 'b', 'done' => false]]],
        ['type' => 'text', 'text' => ' und [] bleibt'],
    ])
        ->and(NoteText::toggle($body, 0, 1, 'Brot'))->toBe("Einkauf:\n[Milch, Brot]\ndann [a, b] und [] bleibt")
        ->and(NoteText::toggle($body, 1, 0, 'a'))->toBe("Einkauf:\n[Milch, #Brot]\ndann [#a, b] und [] bleibt")
        ->and(NoteText::toggle($body, 1, 0, 'x'))->toBeNull();
});
