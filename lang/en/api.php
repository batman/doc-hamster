<?php

declare(strict_types=1);

// D-040: the interface's answers (api/v1).
return [
    'unauthorized' => 'No valid access key.',
    'forbidden' => 'This person may not do that.',
    'read_only' => 'This key may only read.',
    'booking_missing' => 'This booking does not exist (or not for this person).',
    'document_missing' => 'This document does not exist (or not for this person).',
    'match_missing' => 'This booking and this document are neither linked nor suggested.',
    'not_matchable' => 'The document lies in a folder without bank matching and cannot be linked.',
];
