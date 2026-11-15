<?php

declare(strict_types=1);

use App\Infrastructure\Environment\AppVersion;

return [

    /*
    | Release number from the VERSION file (written by
    | .github/workflows/release.yml), else the git short hash from a
    | checkout, else 'dev' (e.g. install.php hosting without git).
    */
    'version' => AppVersion::current(base_path()),

    /*
    | Set to true at the end of setup (browser wizard or `dochamster:setup`,
    | D-024). While false, every page leads to the setup wizard.
    */
    'installed' => (bool) env('DOCHAMSTER_INSTALLED', false),

    /*
    | Reverse proxies besides the local one (comma-separated IPs).
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    /*
    | Point 204 (A37): the ready-made iPhone shortcut "Foto aufnehmen" (iCloud link, placeholders only). Empty until its
    | checked link is there; Einstellungen → Geräte then shows "kommt in Kürze".
    */
    'photo_shortcut' => '',

    /*
    | Point 227: the project's support page (GitHub Sponsors or similar). Empty: no "Unterstützen" link in Einstellungen.
    */
    'support_url' => '',

    /*
    | Point 206 (D-023): "Backup herunterladen". Up to stream_limit bytes the ZIP streams at once; larger ones are
    | built in the background and kept keep_hours. Restore limits guard against zip bombs (untrusted ZIP).
    */
    'backup' => [
        'stream_limit' => (int) env('DOCHAMSTER_BACKUP_STREAM_LIMIT', 500 * 1024 * 1024),
        'keep_hours' => 24,
        'restore_max_entries' => 200_000,
        'restore_max_bytes' => (int) env('DOCHAMSTER_RESTORE_MAX_BYTES', 50 * 1024 * 1024 * 1024),
        'restore_max_ratio' => 200,
    ],

];
