<?php

declare(strict_types=1);

return [
    'title' => 'Text recognition',
    'mode_label' => 'Mode',
    'mode' => [
        'system' => 'System',
        'app' => 'App-owned',
        'browser' => 'Browser',
        'none' => 'not set up',
    ],
    'running_setup' => 'Setting up … (takes about a minute)',
    'broken' => 'Does not work on this server: :reason',
    'browser_hint' => 'The browser reads the images while a DocHamster page is open. PDFs are not possible this way.',
    'browser_missing' => 'The browser fallback could not be loaded: :reason',
    'set_up' => 'Set up text recognition',
    'counts' => [
        'done' => 'recognised',
        'waiting' => 'waiting',
        'failed' => 'failed',
        'unavailable' => 'not possible',
    ],
    'progress' => ':done of :total recognised',
    'current' => 'Now: :title',
    'current_percent' => 'Now: :title (:percent %)',
    'rerun' => 'Recognise all again',
    'rerun_started' => 'All documents are being recognised again.',
    'nav_waiting' => 'Text recognition: :count waiting',
    'state' => [
        'waiting' => 'waiting',
        'running' => 'running',
        'done' => 'recognised',
        'failed' => 'failed',
        'unavailable' => 'not possible',
    ],
    'redo' => 'Recognise again',
];
