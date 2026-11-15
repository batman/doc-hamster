<?php

declare(strict_types=1);

return [
    'title' => 'Optimise document size',
    'intro' => 'Shrinks large scans and photos in the background to 300 dpi – new uploads and all existing documents. Black-and-white and greyscale pages are stored in greyscale. Born-digital PDFs, e-invoices and signed PDFs are never changed.',
    'progress' => 'Progress',
    'counts' => [
        'done' => 'optimised',
        'waiting' => 'waiting',
        'saved' => 'space saved',
    ],
    'off_hint' => 'Switched off – nothing is changed.',
    'busy' => 'Running in the background …',
    'settings' => 'Settings',
    'on' => 'Switched on',
    'mb' => 'From which size (MB per page)',
    'mb_hint' => 'Only files that are larger on average are shrunk. The result is kept only when it is at least 30 % smaller.',
    'original' => 'The original',
    'original_choice' => [
        'trash' => 'To the trash',
        'archive' => 'To an archive folder',
    ],
    'original_hint' => [
        'trash' => 'The original can be restored until the trash is emptied.',
        'archive' => 'The original stays as a document of its own in the archive folder and refers to the optimised one.',
    ],
    'folder' => 'Archive folder',
    'folder_new' => 'New folder “Originale”',
    'folder_hint' => 'Only for “To an archive folder”.',
    'save' => 'Save',
    'saved' => 'Saved.',
    'module_text' => 'Shrinks large scans and photos in the background',
    'module_settings' => 'Open settings',
    'on_pill' => 'on',
    'log' => [
        'on' => 'Optimise document size switched on.',
        'off' => 'Optimise document size switched off.',
        'done' => '“:title” shrunk: :before MB → :after MB.',
    ],
];
