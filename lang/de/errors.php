<?php

declare(strict_types=1);

return [
    'home' => 'Zur Startseite',
    403 => ['title' => 'Kein Zugriff', 'text' => 'Diese Seite ist für Sie nicht freigegeben.'],
    404 => ['title' => 'Seite nicht gefunden', 'text' => 'Diese Seite gibt es nicht (mehr).'],
    419 => ['title' => 'Sitzung abgelaufen', 'text' => 'Bitte laden Sie die Seite neu und versuchen Sie es noch einmal.'],
    429 => ['title' => 'Zu viele Versuche', 'text' => 'Bitte warten Sie eine Minute und versuchen Sie es dann erneut.'],
    500 => ['title' => 'Etwas ist schiefgelaufen', 'text' => 'Ein Fehler auf dem Server. Bitte versuchen Sie es später noch einmal.'],
    503 => ['title' => 'Gerade nicht erreichbar', 'text' => 'DocHamster wird gerade gewartet und ist gleich wieder da.'],
];
