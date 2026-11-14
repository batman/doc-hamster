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
        'speed_opcache' => 'PHP-Zwischenspeicher (OPcache)',
        'speed_debug' => 'Fehlersuche-Modus (APP_DEBUG)',
        'speed_config' => 'Konfiguration vorbereitet',
        'speed_routes' => 'Adressen vorbereitet',
        'speed_events' => 'Ereignisse vorbereitet',
        'speed_xdebug' => 'Xdebug',
        'speed_database' => 'Datenbank-Antwortzeit',
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
        'cron_never' => 'Cron ist noch nicht gelaufen. Cron-Eintrag einrichten, der jede Minute läuft: „:command“.',
        'cron_ok' => 'Zuletzt vor :minutes min gelaufen.',
        'cron_stale' => 'Zuletzt vor :minutes min gelaufen – Cron-Eintrag prüfen.',
        'proc_open_ok' => 'Erlaubt.',
        'proc_open_disabled' => 'Gesperrt – Zusatzfunktionen sind auf diesem Server nicht möglich. Der Kern funktioniert trotzdem.',
        'addon_available' => 'Verfügbar.',
        'addon_missing' => 'Nicht verfügbar – fehlt: :missing. Der Kern funktioniert trotzdem.',
        'speed_opcache_on' => 'Eingeschaltet.',
        'speed_opcache_off' => 'Aus – jede Anfrage übersetzt den ganzen Programmcode neu. In Plesk unter PHP-Einstellungen „opcache.enable“ einschalten.',
        'speed_opcache_cli' => 'Nur auf der Seite Einstellungen → Geschwindigkeit messbar.',
        'speed_debug_off' => 'Aus.',
        'speed_debug_on' => 'An – langsamer und zeigt Interna. In der .env APP_DEBUG=false setzen.',
        'speed_cached' => 'Ja.',
        'speed_not_cached' => 'Nein – wird bei jeder Anfrage neu gelesen. Abhilfe: „Jetzt vorbereiten“ unter Einstellungen → Geschwindigkeit; geschieht nach jedem Update von selbst.',
        'speed_xdebug_off' => 'Nicht geladen.',
        'speed_xdebug_on' => 'Geladen – macht PHP mehrfach langsamer. Auf dem Server abschalten.',
        'speed_db_fast' => ':ms ms je Abfrage.',
        'speed_db_slow' => ':ms ms je Abfrage – die Datenbank ist weit weg oder ausgelastet. Am besten liegt sie auf demselben Server (localhost).',
        'speed_db_unreachable' => 'Nicht erreichbar.',
    ],

    'summary' => [
        'ok' => 'Alle Mindestanforderungen sind erfüllt.',
        'failed' => 'Mindestens eine Mindestanforderung ist nicht erfüllt.',
    ],
];
