<?php

declare(strict_types=1);

return [
    'title' => 'Posteingang',
    'tabs_label' => 'Quellen',
    'all' => 'Alle',
    'sources' => [
        'upload' => 'Upload',
        'printer' => 'Drucker',
        'mail' => 'E-Mail',
        'phone' => 'Handy',
        'assistant' => 'Assistent',
        'api' => 'Programm',
    ],
    'from' => 'Eingang über :source',
    'done' => 'Erledigt (nach „Dokumente allgemein“)',
    'done_all' => 'Alle erledigt (nach „Dokumente allgemein“)',
    'done_one' => '„:title“ ist erledigt.',
    'done_all_status' => '{0} Nichts zu erledigen.|{1} Ein Dokument ist erledigt.|[2,*] :count Dokumente sind erledigt.',
    // Points 182, 183, A359-A361: upload, filing.
    'upload' => 'Hochladen',
    'upload_hint' => 'Fotos oder PDFs in den Posteingang hochladen',
    'open_folders' => 'Ordner daneben öffnen',
    'setup' => ['printer' => 'Drucker einrichten', 'mail' => 'E-Mail einrichten', 'phone' => 'Geräte einrichten', 'assistant' => 'Assistenten einrichten'],
    'file_into' => 'Ablegen in …',
    'recent' => 'Zuletzt benutzt',
    'all_folders' => 'Alle Ordner',
    'expand' => 'Aufklappen',
    'collapse' => 'Zuklappen',
    'filed' => '{1} Abgelegt in „:name“|[2,*] :count Dokumente in „:name“ abgelegt',
    'unfiled' => '{1} Zurück im Posteingang|[2,*] :count Dokumente zurück im Posteingang',
    'not_filed' => 'Posteingang (nicht abgelegt)',
    'drop' => 'Loslassen: in den Posteingang',
    'empty' => 'Hier ist nichts Neues. Was hereinkommt – hochgeladen, gedruckt, per E-Mail oder vom Handy –, steht hier, bis es in einem Ordner abgelegt ist.',
    'empty_source' => [
        'upload' => 'Keine neuen Uploads.',
        'printer' => 'Noch nichts Neues gedruckt. Drucken Sie aus einem beliebigen Programm auf den Drucker „Hamster“, dann erscheint das Dokument hier.',
        'mail' => 'Keine neuen E-Mails.',
        'phone' => 'Nichts Neues vom Handy.',
        'assistant' => 'Nichts Neues vom Assistenten. Mit dem Recht „Dokumente hochladen“ legt er Belege und wichtige E-Mails hier ab.',
    ],
    'mail_title' => 'E-Mail',
    'mail_soon' => 'Dokumente per E-Mail schicken kommt bald. Was dann ankommt, steht im Posteingang unter „E-Mail“.',
];
