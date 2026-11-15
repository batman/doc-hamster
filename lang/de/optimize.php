<?php

declare(strict_types=1);

return [
    'title' => 'Dokumentgröße optimieren',
    'intro' => 'Verkleinert große Scans und Fotos im Hintergrund auf 300 dpi – neue Uploads und alle vorhandenen Dokumente. Schwarzweiß- und Graustufen-Seiten werden in Graustufen gespeichert. Digital erstellte PDFs, E-Rechnungen und signierte PDFs bleiben immer unverändert.',
    'progress' => 'Fortschritt',
    'counts' => [
        'done' => 'optimiert',
        'waiting' => 'warten',
        'saved' => 'Platz gespart',
    ],
    'off_hint' => 'Ausgeschaltet – es wird nichts verändert.',
    'busy' => 'Läuft im Hintergrund …',
    'settings' => 'Einstellungen',
    'on' => 'Eingeschaltet',
    'mb' => 'Ab welcher Größe (MB pro Seite)',
    'mb_hint' => 'Nur Dateien, die im Schnitt größer sind, werden verkleinert. Behalten wird das Ergebnis nur, wenn es mindestens 30 % kleiner ist.',
    'original' => 'Das Original',
    'original_choice' => [
        'trash' => 'In den Papierkorb',
        'archive' => 'In einen Archivordner',
    ],
    'original_hint' => [
        'trash' => 'Das Original lässt sich bis zum Leeren des Papierkorbs wiederherstellen.',
        'archive' => 'Das Original bleibt als eigenes Dokument im Archivordner und verweist auf das verkleinerte.',
    ],
    'folder' => 'Archivordner',
    'folder_new' => 'Neuer Ordner „Originale“',
    'folder_hint' => 'Gilt nur für „In einen Archivordner“.',
    'save' => 'Speichern',
    'saved' => 'Gespeichert.',
    'module_text' => 'Verkleinert große Scans und Fotos im Hintergrund',
    'module_settings' => 'Einstellungen öffnen',
    'on_pill' => 'an',
    'log' => [
        'on' => 'Dokumentgröße optimieren eingeschaltet.',
        'off' => 'Dokumentgröße optimieren ausgeschaltet.',
        'done' => '„:title“ verkleinert: :before MB → :after MB.',
    ],
];
