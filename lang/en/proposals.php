<?php

declare(strict_types=1);

// D-040: Proposals – the assistant's proposals (recommendation tiles).
return [
    'title' => 'Proposals',
    'intro' => 'Your assistant changes nothing itself. Here are its proposals – only your click on “Take over” carries them out.',
    'none' => 'No open proposals.',
    'kinds' => [
        'link' => 'Linking', 'move' => 'Tidying up', 'duplicate' => 'Duplicates', 'delete' => 'Deleting',
        'shrink' => 'Shrinking', 'correct' => 'Corrections', 'setting' => 'Settings',
    ],
    'tile' => [
        'link' => 'Document for a booking', 'move' => 'Move into a folder', 'duplicate' => 'Duplicate document', 'delete' => 'Into the bin',
        'shrink' => 'Too large – shrink', 'correct' => 'Correct a value', 'setting' => 'Switch a setting on',
    ],
    'label' => [
        'booking' => 'Booking', 'document' => 'Document', 'folder' => 'To', 'keep' => 'Stays', 'remove' => 'Bin',
        'trash' => 'Document', 'setting' => 'Switch on',
    ],
    'settings' => ['optimize_on' => 'Optimise document size – shrink large scans and photos automatically'],
    'from' => 'From :name, :date',
    'assistant' => 'Assistant',
    'gone' => 'no longer there',
    'accept' => 'Take over',
    'reject' => 'Reject',
    'swap' => 'Keep the other one',
    'accept_all' => 'Take over all',
    'rejected' => 'Proposal rejected.',
    'all_done' => '{0} Nothing taken over.|{1} :count proposal taken over.|[2,*] :count proposals taken over.',
    'all_failed' => '{1} :count no longer worked.|[2,*] :count no longer worked.',
    'done' => [
        'link' => 'Linked.',
        'move' => 'Moved to “:name”.',
        'trash' => '“:name” is in the :trash.',
        'shrink' => 'Being shrunk – this takes a moment.',
        'correct' => ':name is now :value.',
        'setting_optimize' => 'Optimise document size is switched on.',
    ],
    'gone_booking' => 'The booking no longer exists.',
    'gone_document' => 'The document no longer exists.',
    'gone_folder' => 'The folder no longer exists.',
    'gone_property' => 'This property does not exist.',
    'gone_setting' => 'This setting does not exist.',
    'not_matchable' => 'The document lies in a folder without bank matching.',
    'nothing_to_shrink' => 'There is nothing to shrink.',
    'bad_value' => 'The proposed value does not fit this property.',
    'unknown' => 'Unknown proposal.',
];
