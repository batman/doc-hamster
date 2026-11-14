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
        'api' => 'Programm',
    ],
    'from' => 'Eingang über :source',
    'done' => 'Erledigt',
    'done_all' => 'Alle erledigt',
    'done_one' => '„:title“ ist erledigt.',
    'done_all_status' => '{0} Nichts zu erledigen.|{1} Ein Dokument ist erledigt.|[2,*] :count Dokumente sind erledigt.',
    'empty' => 'Hier ist nichts Neues. Was hereinkommt – hochgeladen, gedruckt, per E-Mail oder vom Handy –, steht hier, bis es geöffnet, verschoben oder als erledigt markiert ist.',
    'empty_source' => [
        'upload' => 'Keine neuen Uploads.',
        'printer' => 'Noch nichts Neues gedruckt. Drucken Sie aus einem beliebigen Programm auf den Drucker „Hamster“, dann erscheint das Dokument hier.',
        'mail' => 'Keine neuen E-Mails.',
        'phone' => 'Nichts Neues vom Handy.',
    ],
    'mail_title' => 'E-Mail',
    'mail_soon' => 'Dokumente per E-Mail schicken kommt bald. Was dann ankommt, steht im Posteingang unter „E-Mail“.',
];
