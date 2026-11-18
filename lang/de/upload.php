<?php

declare(strict_types=1);

// Points 27–29, A77–A81: the Hochlade-Dialog (components/_upload-dialog, core/upload.js).
return [
    'title_one' => '„:name“ wird hochgeladen',
    'title_many' => ':count Dokumente werden hochgeladen',
    'title_done' => 'Hochgeladen',
    'title_problems' => 'Hochgeladen – mit Problemen',
    'target' => 'Nach: :name',
    'stats' => ':done von :total',
    'speed' => ':speed/s',
    'left_seconds' => 'noch etwa :count Sekunden',
    'left_minutes' => 'noch etwa :count Minuten',
    'count' => ':done von :total Dateien',
    'summary' => ':done hochgeladen · :failed nicht hochgeladen · :known schon vorhanden',
    'state' => [
        'waiting' => 'wartet',
        'sending' => 'wird hochgeladen',
        'done' => 'fertig',
        'failed' => 'Fehler',
        'known' => 'schon vorhanden',
    ],
    'error' => [
        'network' => 'Die Verbindung ist abgebrochen.',
        'size' => 'Die Datei ist zu groß (höchstens 25 MB).',
        'other' => 'Der Server hat die Datei nicht angenommen.',
    ],
    'log' => [
        'head' => 'Hochgeladen nach „:name“ am :date',
        'done' => 'Hochgeladen',
        'failed' => 'Nicht hochgeladen',
        'known' => 'Schon vorhanden (als Vorschlag zum Aufräumen unter Vorschläge)',
    ],
    'background' => 'Im Hintergrund weiter',
    'retry' => 'Erneut versuchen',
    'copy' => 'Protokoll kopieren',
    'copied' => 'Protokoll kopiert.',
    'done' => 'Erledigt',
    'show' => 'Hochladen anzeigen',
    'leave' => 'Es werden noch Dateien hochgeladen. Wirklich verlassen?',
];
