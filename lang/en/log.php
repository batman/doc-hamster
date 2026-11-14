<?php

declare(strict_types=1);

// Settings → Log (point 115, A229-A234).
return [
    'title' => 'Log',
    'books' => ['general' => 'General', 'ocr' => 'Text recognition', 'updates' => 'Updates'],
    'levels' => ['event' => 'Event', 'warning' => 'Warning', 'error' => 'Error'],
    'level_filter' => ['' => 'All', 'problems' => 'Errors and warnings', 'errors' => 'Errors only'],
    'engine_label' => 'Recognised by',
    'engine_filter' => ['' => 'All', 'server' => 'Server', 'browser' => 'Browser'],
    'books_label' => 'Logbooks',
    'level_label' => 'Kind',
    'from' => 'From',
    'to' => 'To',
    'search' => 'Search text',
    'filter' => 'Filter',
    'reset_filter' => 'Reset filter',
    'export' => 'Export',
    'clear' => 'Reset',
    'clear_title' => 'Empty logbooks?',
    'clear_text' => 'Every entry of the ticked logbooks is deleted: :books. This cannot be undone.',
    'clear_confirm' => 'Empty',
    'cleared' => 'Emptied: :books.',
    'empty' => 'No entries.',
    'keep' => 'Text recognition keeps 30 days, everything else 12 months.',
    'document_filter' => 'Only document: :title',
    'only_document' => 'Only this document',
    'deleted' => 'deleted document',
    'browser' => 'Browser: :message',
    'columns' => ['time' => 'Time', 'book' => 'Logbook', 'level' => 'Kind', 'message' => 'Message', 'person' => 'Person', 'document' => 'Document'],
    'actions' => [
        'auth' => ['login' => 'Signed in', 'logout' => 'Signed out', 'failed' => 'Sign-in failed (:name)', 'session_ended' => 'Device signed out', 'sessions_ended' => 'All other devices signed out'],
        'log' => ['cleared' => 'Logbooks emptied: :books'],
        'document' => [
            'uploaded' => 'Document uploaded', 'created' => 'Document created', 'changed' => 'Document changed',
            'moved' => 'Document moved', 'trashed' => 'Moved to the bin', 'restored' => 'Restored from the bin',
            'purged' => 'Deleted for good', 'tags_changed' => 'Tags changed', 'taken_over' => 'Document taken over',
            'printed' => 'Printed', 'shared' => 'Link created', 'share_revoked' => 'Link revoked', 'share_opened' => 'Link opened',
            'paid' => 'Marked as paid',
            'trash_emptied' => 'Bin emptied', 'trash_unemptied' => 'Emptying undone', 'trash_swept' => 'Bin cleaned up',
        ],
        'folder' => ['deleted' => 'Folder deleted', 'imported' => 'Folder imported', 'trash_changed' => 'Bin changed'],
        'bank' => ['imported' => 'Statement imported', 'matched' => 'Transaction matched', 'confirmed' => 'Match confirmed', 'unmatched' => 'Match removed', 'account_deleted' => 'Account deleted'],
        'export' => ['created' => 'Export created'],
        'printer' => ['paired' => 'Printer paired', 'revoked' => 'Printer removed', 'setup_started' => 'Printer set-up started'],
        'update' => ['started' => 'Update started'],
    ],
    'new' => 'New',
    // Point 207: one line per organisation when the daily Papierkorb job deleted something.
    'trash_swept' => 'Bin cleaned up: :undo deleted for good after the undo period, :kept after :days days in the bin',
];
