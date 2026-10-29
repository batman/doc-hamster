<?php

declare(strict_types=1);

return [
    'columns' => [
        'status' => 'Status',
        'check' => 'Prüfung',
        'detail' => 'Ergebnis',
    ],

    'status' => [
        'ok' => 'OK',
        'warning' => 'Hinweis',
        'fail' => 'Fehler',
    ],

    'checks' => [
        'php_version' => 'PHP-Version',
        'php_extensions' => 'PHP-Erweiterungen',
        'memory_limit' => 'Arbeitsspeicher (memory_limit)',
        'upload_max_filesize' => 'Upload-Größe (upload_max_filesize)',
        'post_max_size' => 'Anfrage-Größe (post_max_size)',
        'database' => 'Datenbank',
        'https' => 'HTTPS',
        'cron' => 'Cron (Hintergrundaufgaben)',
        'proc_open' => 'Externe Programme (proc_open)',
        'addon_ocr' => 'Zusatzfunktion Texterkennung',
        'addon_speech_to_text' => 'Zusatzfunktion Spracherkennung',
        'addon_heic' => 'Zusatzfunktion HEIC-Umwandlung',
        'addon_vips' => 'Zusatzfunktion schnelle Bildverarbeitung',
    ],

    'details' => [
        'version' => ':version (mindestens :minimum)',
        'version_too_old' => ':version ist zu alt – mindestens :minimum nötig.',
        'all_present' => 'Alle nötigen Erweiterungen sind geladen.',
        'missing' => 'Fehlt: :missing',
        'size' => ':size (mindestens :minimum)',
        'size_too_small' => ':size ist zu wenig – mindestens :minimum nötig.',
        'db_unreachable' => 'Keine Verbindung zur Datenbank. Zugangsdaten in der .env prüfen.',
        'db_version' => ':server :version (mindestens :minimum)',
        'db_version_too_old' => ':server :version ist zu alt – mindestens :minimum nötig.',
        'https_ok' => 'APP_URL nutzt HTTPS.',
        'https_missing' => 'APP_URL muss mit https:// beginnen (feste Domain mit gültigem Zertifikat).',
        'cron_never' => 'Cron ist noch nicht gelaufen. Cron-Eintrag einrichten: jede Minute „php artisan schedule:run“.',
        'cron_ok' => 'Zuletzt vor :minutes min gelaufen.',
        'cron_stale' => 'Zuletzt vor :minutes min gelaufen – Cron-Eintrag prüfen.',
        'proc_open_ok' => 'Erlaubt.',
        'proc_open_disabled' => 'Gesperrt – Zusatzfunktionen sind auf diesem Server nicht möglich. Der Kern funktioniert trotzdem.',
        'addon_available' => 'Verfügbar.',
        'addon_missing' => 'Nicht verfügbar – fehlt: :missing. Der Kern funktioniert trotzdem.',
    ],

    'summary' => [
        'ok' => 'Alle Mindestanforderungen sind erfüllt.',
        'failed' => 'Mindestens eine Mindestanforderung ist nicht erfüllt.',
    ],
];
