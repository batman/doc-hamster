<?php

declare(strict_types=1);

return [
    'columns' => [
        'status' => 'Status',
        'check' => 'Check',
        'detail' => 'Result',
    ],

    'status' => [
        'ok' => 'OK',
        'warning' => 'Note',
        'fail' => 'Error',
    ],

    'checks' => [
        'php_version' => 'PHP version',
        'php_extensions' => 'PHP extensions',
        'memory_limit' => 'Memory (memory_limit)',
        'upload_max_filesize' => 'Upload size (upload_max_filesize)',
        'post_max_size' => 'Request size (post_max_size)',
        'database' => 'Database',
        'https' => 'HTTPS',
        'cron' => 'Cron (background jobs)',
        'proc_open' => 'External programs (proc_open)',
        'addon_ocr' => 'Add-on text recognition',
        'addon_speech_to_text' => 'Add-on speech-to-text',
        'addon_heic' => 'Add-on HEIC conversion',
        'addon_vips' => 'Add-on fast image processing',
    ],

    'details' => [
        'version' => ':version (minimum :minimum)',
        'version_too_old' => ':version is too old – :minimum or newer is required.',
        'all_present' => 'All required extensions are loaded.',
        'missing' => 'Missing: :missing',
        'size' => ':size (minimum :minimum)',
        'size_too_small' => ':size is too small – at least :minimum is required.',
        'db_unreachable' => 'Cannot connect to the database. Check the credentials in .env.',
        'db_version' => ':server :version (minimum :minimum)',
        'db_version_too_old' => ':server :version is too old – :minimum or newer is required.',
        'https_ok' => 'APP_URL uses HTTPS.',
        'https_missing' => 'APP_URL must start with https:// (fixed domain with a valid certificate).',
        'cron_never' => 'Cron has not run yet. Add a cron entry: "php artisan schedule:run" every minute.',
        'cron_ok' => 'Last run :minutes min ago.',
        'cron_stale' => 'Last run :minutes min ago – check the cron entry.',
        'proc_open_ok' => 'Allowed.',
        'proc_open_disabled' => 'Disabled – add-ons are not possible on this server. The core still works.',
        'addon_available' => 'Available.',
        'addon_missing' => 'Not available – missing: :missing. The core still works.',
    ],

    'summary' => [
        'ok' => 'All minimum requirements are met.',
        'failed' => 'At least one minimum requirement is not met.',
    ],
];
