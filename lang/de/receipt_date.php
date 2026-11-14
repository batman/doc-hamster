<?php

declare(strict_types=1);

// Point 214: the Belegdatum (documents.doc_date) – detail line, hints, search filter, logbook.
return [
    'label' => 'Belegdatum',
    'missing' => 'Belegdatum fehlt',
    'edit' => 'Belegdatum ändern',
    'set' => 'Belegdatum eintragen',
    'sources' => [
        'confirmed' => 'bestätigt',
        'recognised' => 'erkannt',
        'exif' => 'Aufnahmedatum des Fotos',
        'pdf' => 'Erstelldatum der PDF',
        'upload' => 'Hochladedatum',
    ],
    'doubtful' => 'Erkannt: :date – nicht übernommen, weil es in der Zukunft oder mehr als 10 Jahre zurück liegt. Bitte prüfen.',
    'filter' => 'Belegdatum',
    'filter_missing' => 'fehlt',
    'chip' => 'Ohne Belegdatum',
    'backfilled' => '{1} Belegdatum für 1 Dokument gesetzt|[2,*] Belegdatum für :count Dokumente gesetzt',
];
