<?php

declare(strict_types=1);

// Einstellungen → Log (point 115, A229-A234).
return [
    'title' => 'Log',
    'books' => ['general' => 'Allgemein', 'ocr' => 'Texterkennung', 'updates' => 'Updates'],
    'levels' => ['event' => 'Ereignis', 'warning' => 'Warnung', 'error' => 'Fehler'],
    'level_filter' => ['' => 'Alle', 'problems' => 'Fehler und Warnungen', 'errors' => 'Nur Fehler'],
    'engine_label' => 'Erkannt von',
    'engine_filter' => ['' => 'Alle', 'server' => 'Server', 'browser' => 'Browser'],
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
        'auth' => ['login' => 'Angemeldet', 'logout' => 'Abgemeldet', 'failed' => 'Anmeldung fehlgeschlagen (:name)', 'session_ended' => 'Gerät abgemeldet', 'sessions_ended' => 'Alle anderen Geräte abgemeldet'],
        'log' => ['cleared' => 'Logbücher geleert: :books'],
        'document' => [
            'uploaded' => 'Dokument hochgeladen', 'created' => 'Dokument angelegt', 'changed' => 'Dokument geändert',
            'moved' => 'Dokument verschoben', 'trashed' => 'In den Papierkorb gelegt', 'restored' => 'Aus dem Papierkorb geholt',
            'purged' => 'Endgültig gelöscht', 'tags_changed' => 'Tags geändert', 'taken_over' => 'Dokument übernommen',
            'printed' => 'Gedruckt', 'shared' => 'Link erstellt', 'share_revoked' => 'Link zurückgezogen', 'share_opened' => 'Link geöffnet',
            'paid' => 'Als bezahlt markiert',
            'trash_emptied' => 'Papierkorb geleert', 'trash_unemptied' => 'Leerung rückgängig gemacht', 'trash_swept' => 'Papierkorb aufgeräumt',
        ],
        'folder' => ['deleted' => 'Ordner gelöscht', 'imported' => 'Ordner importiert', 'trash_changed' => 'Papierkorb geändert'],
        'bank' => ['imported' => 'Kontoauszug importiert', 'matched' => 'Buchung zugeordnet', 'confirmed' => 'Zuordnung bestätigt', 'unmatched' => 'Zuordnung gelöst', 'account_deleted' => 'Konto gelöscht'],
        'export' => ['created' => 'Export erstellt'],
        'backup' => ['started' => 'Backup gestartet', 'downloaded' => 'Backup heruntergeladen'],
        'printer' => ['paired' => 'Drucker verbunden', 'revoked' => 'Drucker getrennt', 'setup_started' => 'Drucker-Einrichtung gestartet'],
        'update' => ['started' => 'Update gestartet'],
        // Points 210, 211: Steuerberater-Zugang and Belegpaket.
        'advisor' => [
            'access_created' => 'Steuerberater-Zugang angelegt', 'access_changed' => 'Steuerberater-Freigabe geändert', 'access_revoked' => 'Steuerberater-Zugang beendet',
            'password_reset' => 'Steuerberater: neues Einmalpasswort', 'password_changed' => 'Steuerberater: Passwort geändert', 'password_reset_by_mail' => 'Steuerberater: Passwort per E-Mail-Link neu gesetzt',
            'list_viewed' => 'Steuerberater: Liste angesehen', 'document_viewed' => 'Steuerberater: Beleg angesehen', 'file_downloaded' => 'Steuerberater: Datei heruntergeladen', 'pack_downloaded' => 'Steuerberater: Belegpaket heruntergeladen',
        ],
        'pack' => ['created' => 'Belegpaket erstellt', 'downloaded' => 'Belegpaket heruntergeladen', 'document_added' => 'Ins Belegpaket aufgenommen', 'document_removed' => 'Aus dem Belegpaket genommen'],
    ],
    'new' => 'Neu',
    // Point 207: one line per organisation when the daily Papierkorb job deleted something.
    'trash_swept' => 'Papierkorb aufgeräumt: :undo nach Ablauf der Rückgängig-Frist, :kept nach :days Tagen im Papierkorb endgültig gelöscht',
];
