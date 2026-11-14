<?php

declare(strict_types=1);

return [
    'title' => 'Inbox',
    'tabs_label' => 'Sources',
    'all' => 'All',
    'sources' => [
        'upload' => 'Upload',
        'printer' => 'Printer',
        'mail' => 'E-mail',
        'phone' => 'Phone',
        'api' => 'Program',
    ],
    'from' => 'Came in via :source',
    'done' => 'Done (into "General documents")',
    'done_all' => 'All done (into "General documents")',
    'done_one' => '":title" is done.',
    'done_all_status' => '{0} Nothing to do.|{1} One document is done.|[2,*] :count documents are done.',
    'upload' => 'Upload',
    'upload_hint' => 'Upload photos or PDFs into the inbox',
    'open_folders' => 'Open folders beside',
    'file_into' => 'File into …',
    'recent' => 'Recently used',
    'all_folders' => 'All folders',
    'expand' => 'Expand',
    'collapse' => 'Collapse',
    'filed' => '{1} Filed into ":name"|[2,*] :count documents filed into ":name"',
    'unfiled' => '{1} Back in the inbox|[2,*] :count documents back in the inbox',
    'not_filed' => 'Inbox (not filed)',
    'drop' => 'Drop: into the inbox',
    'empty' => 'Nothing new here. What comes in – uploaded, printed, by e-mail or from a phone – stays here until it is filed into a folder.',
    'empty_source' => [
        'upload' => 'No new uploads.',
        'printer' => 'Nothing new printed yet. Print from any program on the printer "Hamster" and the document shows up here.',
        'mail' => 'No new e-mails.',
        'phone' => 'Nothing new from a phone.',
    ],
    'mail_title' => 'E-mail',
    'mail_soon' => 'Sending documents by e-mail comes soon. What arrives then shows in the inbox under "E-mail".',
];
