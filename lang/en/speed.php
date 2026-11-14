<?php

declare(strict_types=1);

// Settings → Speed (point 164.7).
return [
    'title' => 'Speed',
    'saved' => 'Saved.',
    'optimized' => 'Prepared: configuration, routes and pages are compiled now.',
    'optimize_failed' => 'Preparing did not work; the app runs as before.',
    'reset_done' => 'The measurements were deleted.',

    'measured' => 'Measurements (last 7 days)',
    'measured_note' => 'Server time per page, without transfer. Goal: below 150 ms. "Window" = loaded into a window.',
    'none' => 'No measurements yet.',
    'row' => 'Median :median ms · slowest 5 %: :p95 ms · :count requests · :queries queries (:db ms)',
    'window' => 'Window',
    'reset' => 'Reset',

    'checks' => 'Checks',
    'optimize' => 'Prepare now',

    'tests' => 'Self test',
    'tests_note' => 'Measures from this browser. Takes a few seconds.',
    'run' => 'Start test',
    'running' => 'running …',
    'failed' => 'failed',
    'test' => [
        'server' => ['title' => 'Server without the app', 'text' => 'An empty address: what the server needs before the app works.'],
        'db' => ['title' => 'Database', 'text' => 'Answer time of an empty query (measured 10 times).'],
        'roundtrip' => ['title' => 'Browser ↔ server', 'text' => 'There and back, without the server\'s work.'],
        'download' => ['title' => 'Download', 'text' => 'Load 2 MB from the server.'],
        'window' => ['title' => 'Load a window', 'text' => 'Load the window "Documents", as a click does.'],
    ],
    'result' => [
        'server' => ':server ms in the server, :total ms in total',
        'db' => ':ms ms per query',
        'roundtrip' => ':ms ms',
        'download' => ':mbit Mbit/s',
        'window' => ':server ms in the server, :total ms until shown',
    ],

    'options' => 'Options',
    'prefetch' => 'Prefetch',
    'prefetch_options' => [
        'off' => ['title' => 'Off', 'text' => 'Load only what is clicked. For very slow connections or small data plans.'],
        'normal' => ['title' => 'Normal', 'text' => 'Load small windows (menus, overviews) in the background after sign-in; load a window as soon as the pointer rests on it.'],
        'strong' => ['title' => 'Strong', 'text' => 'Also the next documents, details and thumbnails whenever the browser is idle. Fastest, uses more data.'],
    ],
    'thumbs' => 'Thumbnails',
    'thumbs_options' => [
        'normal' => ['title' => 'Normal', 'text' => '480 pixels, good quality.'],
        'small' => ['title' => 'Small', 'text' => '320 pixels, a little coarser, loads faster. Applies to new documents.'],
    ],

    'routes' => [
        'home' => 'Start',
        'documents.index' => 'Documents',
        'documents.show' => 'Document',
        'documents.trash' => 'Trash',
        'inbox.index' => 'Inbox',
        'bank.index' => 'Bank',
        'bank.account' => 'Bank account',
        'bank.show' => 'Booking',
        'bank.receipts' => 'Documents without booking',
        'settings.index' => 'Settings',
        'settings.group' => 'Settings (group)',
        'tags.css' => 'Colours (tags.css)',
        'ocr.status' => 'Text recognition (state)',
        'queue.tick' => 'Background work',
    ],
];
