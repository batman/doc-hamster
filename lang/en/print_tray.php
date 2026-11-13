<?php

declare(strict_types=1);

return [
    'title' => 'Print tray',
    'setup' => 'Set up printer',
    'empty' => 'Nothing printed yet. Print from any program on the printer "Hamster" and the document shows up here.',
    'printed_at' => 'printed on :date',
    'accept' => 'Take over as document',
    'accepted' => '":title" is now a document.',
    'default_title' => 'Print of :date',
    'new_printer' => 'Windows PC',
    'printers' => 'Your computers with the printer "Hamster"',
    'no_printers' => 'No computer set up yet.',
    'last_used' => 'last: :date',
    'paired_at' => 'paired on :date',
    'delete' => 'Delete',
    'delete_title' => 'Delete entry?',
    'delete_text' => 'Only this entry is deleted. Prints from this computer with this pairing will no longer arrive.',
    'cancel' => 'Cancel',
    'deleted' => '":name" is deleted.',
    'settings_title' => 'Printer',
    'no_printer_hint' => 'No printer set up yet –',
    'no_printer_link' => 'Settings → Printer',
    'howto_title' => 'How to set up the printer (Windows)',
    'howto' => [
        'Click "Set up printer". This downloads a small file for Windows that is already linked to your account. It is valid for 24 hours and for one computer.',
        'Double-click the file and confirm the Windows question for administrator rights.',
        'Done: every program now has the printer "Hamster". What you print on it lands in the print tray – if the computer was offline, a little later.',
        'Remove: uninstall "DocHamster Drucker" under Windows "Apps". Delete its entry here, then nothing from that computer arrives any more.',
    ],
];
