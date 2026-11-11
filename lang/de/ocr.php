<?php

declare(strict_types=1);

return [
    'title' => 'Texterkennung',
    'mode_label' => 'Art',
    'mode' => [
        'system' => 'System',
        'app' => 'App-eigen',
        'browser' => 'Browser',
        'none' => 'nicht eingerichtet',
    ],
    'running_setup' => 'Wird eingerichtet … (dauert etwa eine Minute)',
    'broken' => 'Geht nicht auf diesem Server: :reason',
    'browser_hint' => 'Der Browser liest die Bilder, solange eine Seite von DocHamster offen ist. PDFs gehen so nicht.',
    'browser_missing' => 'Der Browser-Ersatz konnte nicht geladen werden: :reason',
    'set_up' => 'Texterkennung einrichten',
    'counts' => [
        'done' => 'erkannt',
        'waiting' => 'wartet',
        'failed' => 'fehlgeschlagen',
        'unavailable' => 'nicht möglich',
    ],
    'progress' => ':done von :total erkannt',
    'current' => 'Gerade: :title',
    'current_percent' => 'Gerade: :title (:percent %)',
    'rerun' => 'Alle neu erkennen',
    'rerun_started' => 'Alle Dokumente werden neu erkannt.',
    'nav_waiting' => 'Texterkennung: :count wartend',
    'state' => [
        'waiting' => 'wartet',
        'running' => 'läuft gerade',
        'done' => 'erkannt',
        'failed' => 'fehlgeschlagen',
        'unavailable' => 'nicht möglich',
    ],
    'redo' => 'Neu erkennen',
];
