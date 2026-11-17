<?php

declare(strict_types=1);

// D-040: Vorschläge – the assistant's proposals (Empfehlungskacheln).
return [
    'title' => 'Vorschläge',
    'intro' => 'Ihr Assistent ändert nichts selbst. Hier stehen seine Vorschläge – erst Ihr Klick auf „Übernehmen“ führt sie aus.',
    'none' => 'Keine offenen Vorschläge.',
    'kinds' => [
        'link' => 'Verknüpfen', 'move' => 'Aufräumen', 'duplicate' => 'Duplikate', 'delete' => 'Löschen',
        'shrink' => 'Verkleinern', 'correct' => 'Korrekturen', 'setting' => 'Einstellungen',
    ],
    'tile' => [
        'link' => 'Dokument zur Buchung', 'move' => 'In Ordner verschieben', 'duplicate' => 'Doppeltes Dokument', 'delete' => 'In den Papierkorb',
        'shrink' => 'Zu groß – verkleinern', 'correct' => 'Wert korrigieren', 'setting' => 'Einstellung einschalten',
    ],
    'label' => [
        'booking' => 'Buchung', 'document' => 'Dokument', 'folder' => 'Nach', 'keep' => 'Bleibt', 'remove' => 'Papierkorb',
        'trash' => 'Dokument', 'setting' => 'Einschalten',
    ],
    'settings' => ['optimize_on' => 'Dokumentgröße optimieren – große Scans und Fotos automatisch verkleinern'],
    'from' => 'Von :name, :date',
    'assistant' => 'Assistent',
    'gone' => 'nicht mehr vorhanden',
    'accept' => 'Übernehmen',
    'reject' => 'Ablehnen',
    'swap' => 'Das andere behalten',
    'accept_all' => 'Alle übernehmen',
    'rejected' => 'Vorschlag abgelehnt.',
    'all_done' => '{0} Nichts übernommen.|{1} :count Vorschlag übernommen.|[2,*] :count Vorschläge übernommen.',
    'all_failed' => '{1} :count ging nicht mehr.|[2,*] :count gingen nicht mehr.',
    'done' => [
        'link' => 'Verknüpft.',
        'move' => 'Nach „:name“ verschoben.',
        'trash' => '„:name“ liegt im :trash.',
        'shrink' => 'Wird verkleinert – das dauert einen Moment.',
        'correct' => ':name ist jetzt :value.',
        'setting_optimize' => 'Dokumentgröße optimieren ist eingeschaltet.',
    ],
    'gone_booking' => 'Die Buchung gibt es nicht mehr.',
    'gone_document' => 'Das Dokument gibt es nicht mehr.',
    'gone_folder' => 'Den Ordner gibt es nicht mehr.',
    'gone_property' => 'Diese Eigenschaft gibt es nicht.',
    'gone_setting' => 'Diese Einstellung gibt es nicht.',
    'not_matchable' => 'Das Dokument liegt in einem Ordner ohne Bankabgleich.',
    'nothing_to_shrink' => 'Daran ist nichts zu verkleinern.',
    'bad_value' => 'Der vorgeschlagene Wert passt nicht zu dieser Eigenschaft.',
    'unknown' => 'Unbekannter Vorschlag.',
];
