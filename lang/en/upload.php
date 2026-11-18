<?php

declare(strict_types=1);

// Points 27–29, A77–A81: the upload dialog (components/_upload-dialog, core/upload.js).
return [
    'title_one' => 'Uploading “:name”',
    'title_many' => 'Uploading :count documents',
    'title_done' => 'Uploaded',
    'title_problems' => 'Uploaded – with problems',
    'target' => 'To: :name',
    'stats' => ':done of :total',
    'speed' => ':speed/s',
    'left_seconds' => 'about :count seconds left',
    'left_minutes' => 'about :count minutes left',
    'count' => ':done of :total files',
    'summary' => ':done uploaded · :failed not uploaded · :known already there',
    'state' => [
        'waiting' => 'waiting',
        'sending' => 'uploading',
        'done' => 'done',
        'failed' => 'error',
        'known' => 'already there',
    ],
    'error' => [
        'network' => 'The connection broke off.',
        'size' => 'The file is too large (at most 25 MB).',
        'other' => 'The server did not accept the file.',
    ],
    'logged' => 'Saved in the log.',
    'in_log' => 'Also saved in the log',
    'protocol' => 'Protocol',
    'log' => [
        'line' => 'Uploaded to “:name”: :done done · :failed not uploaded · :known already there',
        'head' => 'Uploaded to “:name” on :date',
        'done' => 'Uploaded',
        'failed' => 'Not uploaded',
        'known' => 'Already there (a tidy-up proposal under Proposals)',
    ],
    'background' => 'Continue in the background',
    'retry' => 'Try again',
    'done' => 'Done',
    'show' => 'Show upload',
    'leave' => 'Files are still uploading. Leave anyway?',
];
