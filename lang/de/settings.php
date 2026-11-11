<?php

declare(strict_types=1);

return [
    'title' => 'Einstellungen',
    'wall' => 'Spaltenbreite ändern',
    'fold' => 'Zuklappen',
    'expand' => ':name aufklappen',
    'open' => 'Aufklappen',
    'addons' => [
        'ocr' => 'Texterkennung (OCR)',
        'available' => 'verfügbar',
        'missing' => 'fehlt – auf dem Server installieren: :install',
        'available_system' => 'verfügbar (System)',
        'available_app' => 'verfügbar (App-eigen)',
        'not_set_up' => 'nicht eingerichtet',
        'running' => 'wird eingerichtet … (dauert etwa eine Minute)',
        'broken' => 'geht nicht auf diesem Server (:reason).',
        'browser' => 'Browser-Ersatz aktiv.',
        'browser_missing' => 'Browser-Ersatz konnte nicht geladen werden.',
        'set_up' => 'Texterkennung einrichten',
    ],
    'groups' => [
        'people' => 'Personen & Rollen',
        'bank' => 'Bank',
        'tags' => 'Tags',
        'colors' => 'Farben',
        'export' => 'Export',
        'update' => 'Update',
    ],
    'links' => [
        'members' => ['title' => 'Personen', 'text' => 'Wer Zugang hat'],
        'roles' => ['title' => 'Rollen', 'text' => 'Was eine Rolle darf'],
        'tags' => ['title' => 'Tags', 'text' => 'Etiketten für Dokumente verwalten'],
        'colors' => ['title' => 'Farben', 'text' => 'Farbpalette für Notizen, Ordner und Tags'],
        'bank' => ['title' => 'Konten und Import', 'text' => 'Kontoauszüge importieren, Konten verwalten'],
        'export' => ['title' => 'Export', 'text' => 'Dokumente als ZIP, zum Beispiel für den Steuerberater'],
        'shares' => ['title' => 'Links', 'text' => 'Gültigkeit und gültige Links zum Versenden'],
        'update' => ['title' => 'Update', 'text' => 'Neue Version suchen und installieren'],
        'phone' => ['title' => 'Handy-Test', 'text' => 'Prüfen, ob dein Handy alles kann'],
    ],
];
