<?php

declare(strict_types=1);

// Einstellungen → Log (point 115, A229-A234).
return [
    'title' => 'Log',
    'books' => ['general' => 'Allgemein', 'ocr' => 'Texterkennung', 'updates' => 'Updates'],
    'levels' => ['event' => 'Ereignis', 'warning' => 'Warnung', 'error' => 'Fehler'],
    'level_filter' => ['' => 'Alle', 'problems' => 'Fehler und Warnungen', 'errors' => 'Nur Fehler'],
    'books_label' => 'Logbücher',
    'level_label' => 'Art',
    'from' => 'Von',
    'to' => 'Bis',
    'search' => 'Text suchen',
    'filter' => 'Filtern',
    'reset_filter' => 'Filter zurücksetzen',
    'export' => 'Exportieren',
    'clear' => 'Zurücksetzen',
    'clear_title' => 'Logbücher leeren?',
    'clear_text' => 'Alle Einträge der angehakten Logbücher werden gelöscht: :books. Das lässt sich nicht rückgängig machen.',
    'clear_confirm' => 'Leeren',
    'cleared' => 'Geleert: :books.',
    'empty' => 'Keine Einträge.',
    'keep' => 'Texterkennung bleibt 30 Tage, alles andere 12 Monate.',
    'document_filter' => 'Nur Dokument: :title',
    'only_document' => 'Nur dieses Dokument',
    'deleted' => 'gelöschtes Dokument',
    'browser' => 'Browser: :message',
    'columns' => ['time' => 'Zeit', 'book' => 'Logbuch', 'level' => 'Art', 'message' => 'Meldung', 'person' => 'Person', 'document' => 'Dokument'],
    'actions' => [
        'auth' => ['login' => 'Angemeldet', 'logout' => 'Abgemeldet', 'failed' => 'Anmeldung fehlgeschlagen (:name)'],
        'log' => ['cleared' => 'Logbücher geleert: :books'],
        'document' => [
            'uploaded' => 'Dokument hochgeladen', 'created' => 'Dokument angelegt', 'changed' => 'Dokument geändert',
            'moved' => 'Dokument verschoben', 'trashed' => 'In den Papierkorb gelegt', 'restored' => 'Aus dem Papierkorb geholt',
            'purged' => 'Endgültig gelöscht', 'tags_changed' => 'Tags geändert', 'taken_over' => 'Dokument übernommen',
            'printed' => 'Gedruckt', 'shared' => 'Link erstellt', 'share_revoked' => 'Link zurückgezogen', 'share_opened' => 'Link geöffnet',
        ],
        'folder' => ['deleted' => 'Ordner gelöscht', 'imported' => 'Ordner importiert', 'trash_changed' => 'Papierkorb geändert'],
        'bank' => ['imported' => 'Kontoauszug importiert', 'matched' => 'Buchung zugeordnet', 'unmatched' => 'Zuordnung gelöst', 'account_deleted' => 'Konto gelöscht'],
        'export' => ['created' => 'Export erstellt'],
        'printer' => ['paired' => 'Drucker verbunden', 'revoked' => 'Drucker getrennt', 'setup_started' => 'Drucker-Einrichtung gestartet'],
        'update' => ['started' => 'Update gestartet'],
    ],
    'new' => 'Neu',
];
