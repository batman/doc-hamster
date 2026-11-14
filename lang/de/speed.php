<?php

declare(strict_types=1);

// Einstellungen → Geschwindigkeit (point 164.7).
return [
    'title' => 'Geschwindigkeit',
    'saved' => 'Gespeichert.',
    'optimized' => 'Vorbereitet: Konfiguration, Adressen und Seiten sind jetzt fertig übersetzt.',
    'optimize_failed' => 'Das Vorbereiten hat nicht geklappt; die App läuft wie bisher.',
    'reset_done' => 'Die Messwerte wurden gelöscht.',

    'measured' => 'Messwerte (letzte 7 Tage)',
    'measured_note' => 'Zeit des Servers je Seite, ohne Übertragung. Ziel: unter 150 ms. „Fenster“ = in ein Fenster nachgeladen.',
    'none' => 'Noch keine Messwerte.',
    'row' => 'Mittel :median ms · langsamste 5 %: :p95 ms · :count Aufrufe · :queries Abfragen (:db ms)',
    'window' => 'Fenster',
    'reset' => 'Zurücksetzen',

    'checks' => 'Prüfungen',
    'optimize' => 'Jetzt vorbereiten',

    'tests' => 'Selbsttest',
    'tests_note' => 'Misst von diesem Browser aus. Dauert ein paar Sekunden.',
    'run' => 'Test starten',
    'running' => 'läuft …',
    'failed' => 'fehlgeschlagen',
    'test' => [
        'server' => ['title' => 'Server ohne App', 'text' => 'Leere Adresse: was der Server braucht, bevor die App arbeitet.'],
        'db' => ['title' => 'Datenbank', 'text' => 'Antwortzeit einer leeren Abfrage (10 × gemessen).'],
        'roundtrip' => ['title' => 'Weg Browser ↔ Server', 'text' => 'Hin und zurück, ohne die Arbeit des Servers.'],
        'download' => ['title' => 'Download', 'text' => '2 MB vom Server laden.'],
        'window' => ['title' => 'Fenster laden', 'text' => 'Das Fenster „Dokumente“ nachladen, wie beim Klick.'],
    ],
    'result' => [
        'server' => ':server ms im Server, :total ms gesamt',
        'db' => ':ms ms je Abfrage',
        'roundtrip' => ':ms ms',
        'download' => ':mbit Mbit/s',
        'window' => ':server ms im Server, :total ms bis angezeigt',
    ],

    'options' => 'Optionen',
    'prefetch' => 'Vorladen',
    'prefetch_options' => [
        'off' => ['title' => 'Aus', 'text' => 'Nur laden, was angeklickt wird. Für sehr langsame Verbindungen oder knappe Datenvolumen.'],
        'normal' => ['title' => 'Normal', 'text' => 'Kleine Fenster (Menüs, Übersichten) nach der Anmeldung im Hintergrund laden; ein Fenster laden, sobald der Zeiger darauf ruht.'],
        'strong' => ['title' => 'Stark', 'text' => 'Zusätzlich die nächsten Dokumente, Details und Vorschaubilder, sobald der Browser nichts zu tun hat. Am schnellsten, braucht mehr Daten.'],
    ],
    'thumbs' => 'Vorschaubilder',
    'thumbs_options' => [
        'normal' => ['title' => 'Normal', 'text' => '480 Pixel, gute Qualität.'],
        'small' => ['title' => 'Klein', 'text' => '320 Pixel, etwas gröber, lädt schneller. Gilt für neue Dokumente.'],
    ],

    // Readable names of the measured pages; others show their internal name.
    'routes' => [
        'home' => 'Start',
        'documents.index' => 'Dokumente',
        'documents.show' => 'Dokument',
        'documents.trash' => 'Papierkorb',
        'inbox.index' => 'Posteingang',
        'bank.index' => 'Bank',
        'bank.account' => 'Bankkonto',
        'bank.show' => 'Buchung',
        'bank.receipts' => 'Dokumente ohne Buchung',
        'settings.index' => 'Einstellungen',
        'settings.group' => 'Einstellungen (Gruppe)',
        'tags.css' => 'Farben (tags.css)',
        'ocr.status' => 'Texterkennung (Stand)',
        'queue.tick' => 'Hintergrundarbeit',
    ],
];
