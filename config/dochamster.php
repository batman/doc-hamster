<?php

declare(strict_types=1);

return [

    /*
    | Set to true at the end of setup (browser wizard or `dochamster:setup`,
    | D-024). While false, every page leads to the setup wizard.
    */
    'installed' => (bool) env('DOCHAMSTER_INSTALLED', false),

];
