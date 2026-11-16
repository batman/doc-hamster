<?php

declare(strict_types=1);

// D-040: the interface's answers (api/v1).
return [
    'unauthorized' => 'Kein gültiger Zugangsschlüssel.',
    'forbidden' => 'Das darf diese Person nicht.',
    'read_only' => 'Dieser Schlüssel darf nur lesen.',
    'booking_missing' => 'Diese Buchung gibt es nicht (oder nicht für diese Person).',
    'document_missing' => 'Dieses Dokument gibt es nicht (oder nicht für diese Person).',
    'match_missing' => 'Diese Buchung und dieses Dokument sind nicht verknüpft und nicht vorgeschlagen.',
    'not_matchable' => 'Das Dokument liegt in einem Ordner ohne Bankabgleich und kann nicht verknüpft werden.',
];
