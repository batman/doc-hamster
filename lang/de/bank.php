<?php

declare(strict_types=1);

return [
    'title' => 'Bank',
    'import' => 'Kontoauszug importieren',
    'views' => [
        'open' => 'Buchungen ohne Beleg',
        'matched' => 'Zugeordnete Buchungen',
        'receipts' => 'Belege ohne Buchung',
    ],
    'empty' => [
        'open' => 'Keine offenen Buchungen.',
        'matched' => 'Noch keine Buchung zugeordnet.',
        'receipts' => 'Alle Belege sind zugeordnet.',
    ],
    'receipts_count' => '{1} 1 Beleg|[2,*] :count Belege',

    'import_hint' => 'Laden Sie den Kontoauszug als CSV-Datei hoch, wie Ihre Bank ihn ausgibt. Bereits importierte Buchungen werden nicht doppelt angelegt.',
    'file' => 'CSV-Datei',
    'account' => 'Konto',
    'new_account' => 'Neues Konto',
    'account_name' => 'Name des neuen Kontos',
    'currency' => 'Währung des neuen Kontos',
    'next' => 'Weiter',
    'wrong_type' => 'Diese Datei lässt sich nicht als Kontoauszug lesen. Bitte eine CSV-Datei wählen.',
    'expired' => 'Die hochgeladene Datei ist nicht mehr da. Bitte noch einmal hochladen.',

    'columns_title' => 'Spalten zuordnen',
    'columns_hint' => 'Konto: :account. Prüfen Sie, welche Spalte was enthält.',
    'columns' => [
        'date' => 'Buchungsdatum',
        'amount' => 'Betrag',
        'counterparty' => 'Empfänger oder Auftraggeber',
        'purpose' => 'Verwendungszweck',
    ],
    'no_column' => 'Keine Spalte',
    'preview' => 'Die ersten Zeilen der Datei',
    'run' => 'Importieren',
    'none_readable' => 'Mit dieser Zuordnung ließ sich keine Zeile lesen. Bitte Datum und Betrag prüfen.',
    'imported' => 'Neu: :new. Schon vorhanden: :known. Nicht lesbar: :unreadable.',

    'accounts' => 'Konten',
    'transactions_count' => '{0} keine Buchungen|{1} 1 Buchung|[2,*] :count Buchungen',
    'delete_account' => 'Konto löschen',
    'confirm_delete' => 'Konto „:name“ mit :count Buchungen löschen? Auch die Zuordnungen zu Belegen gehen verloren, die Belege selbst bleiben.',
    'account_deleted' => 'Konto gelöscht.',

    'linked' => 'Zugeordnete Belege',
    'hidden' => '{1} Dazu gehört 1 Beleg, den Sie nicht sehen dürfen.|[2,*] Dazu gehören :count Belege, die Sie nicht sehen dürfen.',
    'candidates' => 'Beleg zuordnen',
    'candidates_hint' => 'Die Belege stehen nach Nähe zum Buchungsdatum sortiert.',
    'no_candidates' => 'Keine freien Belege vorhanden.',
    'link' => 'Zuordnen',
    'unlink' => 'Zuordnung lösen',
    'matched' => 'Beleg zugeordnet.',
    'unmatched' => 'Zuordnung gelöst.',
    'receipt_gone' => 'Diesen Beleg gibt es nicht mehr.',
    'back' => 'Zurück zur Liste',
    'pages' => 'Seiten',
    'previous' => 'Zurück',
];
