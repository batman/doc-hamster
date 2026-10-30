<?php

declare(strict_types=1);

return [

    /*
    | Written into the release package by .github/workflows/release.yml.
    */
    'version' => is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : 'dev',

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
