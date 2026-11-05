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

];
