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
    | Appended to styles, scripts and the service worker: the version plus when
    | they last changed, so a phone never keeps old app files after an update
    | or a preview install with the same version number.
    */
    'asset_version' => (is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : 'dev')
        .'-'.max(array_map(static fn (string $file): int => is_file(base_path($file)) ? (int) filemtime(base_path($file)) : 0, ['public/css/app.css', 'public/js/app.js', 'public/js/hamster.js', 'public/sw.js'])),

    /*
    | Set to true at the end of setup (browser wizard or `dochamster:setup`,
    | D-024). While false, every page leads to the setup wizard.
    */
    'installed' => (bool) env('DOCHAMSTER_INSTALLED', false),

    /*
    | Reverse proxies besides the local one (comma-separated IPs).
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

];
