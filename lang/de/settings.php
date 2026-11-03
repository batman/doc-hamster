<?php

declare(strict_types=1);

return [
    'title' => 'Einstellungen',
    'groups' => [
        'access' => 'Zugang',
        'documents' => 'Belege',
        'bank' => 'Bank',
        'export' => 'Export',
        'system' => 'System',
    ],
    'links' => [
        'members' => ['title' => 'Personen', 'text' => 'Wer Zugang hat'],
        'roles' => ['title' => 'Rollen', 'text' => 'Was eine Rolle darf'],
        'tags' => ['title' => 'Tags', 'text' => 'Etiketten für Belege verwalten'],
        'bank' => ['title' => 'Konten und Import', 'text' => 'Kontoauszüge importieren, Konten verwalten'],
        'export' => ['title' => 'Export', 'text' => 'Belege als ZIP, zum Beispiel für den Steuerberater'],
        'update' => ['title' => 'Update', 'text' => 'Neue Version suchen und installieren'],
        'phone' => ['title' => 'Handy-Test', 'text' => 'Prüfen, ob dein Handy alles kann'],
    ],
];
