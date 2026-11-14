<?php

declare(strict_types=1);

// Point 214: the receipt date (documents.doc_date) – detail line, hints, search filter, logbook.
return [
    'label' => 'Receipt date',
    'missing' => 'Receipt date missing',
    'edit' => 'Change receipt date',
    'set' => 'Enter receipt date',
    'sources' => [
        'confirmed' => 'confirmed',
        'recognised' => 'recognised',
        'exif' => 'photo capture date',
        'pdf' => 'PDF creation date',
        'upload' => 'upload date',
    ],
    'doubtful' => 'Recognised: :date – not taken because it lies in the future or more than 10 years back. Please check.',
    'filter' => 'Receipt date',
    'filter_missing' => 'missing',
    'chip' => 'Without receipt date',
    'backfilled' => '{1} Receipt date set for 1 document|[2,*] Receipt date set for :count documents',
];
