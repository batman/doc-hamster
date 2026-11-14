<?php

declare(strict_types=1);

// GiroCode card in the detail window (point 120, A237-A239, A247, A249, A250).
return [
    'title' => 'GiroCode',
    'fields' => [
        'payee' => 'Empfänger',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'amount' => 'Betrag',
        'reference' => 'Verwendungszweck',
    ],
    'reference_invoice' => 'Rechnung :number',
    'reference_customer' => 'Kunde :number',
    'reference_hint' => 'Leer lassen = aus Rechnungs- und Kundennummer',
    'empty' => 'fehlt',
    'suggested' => 'vorgeschlagen',
    'unsure' => 'unsicher',
    'other_amounts' => 'Andere Beträge im Dokument',
    'take_amount' => 'Als Betrag übernehmen: :value',
    'swap' => 'Im Dokument steht „:original“ – das ist keine gültige IBAN. Gemeint ist wohl:',
    'take_iban' => 'Als IBAN übernehmen: :value',
    'missing' => 'Für den GiroCode fehlt: :fields',
    'not_euro' => 'Einen GiroCode gibt es nur für Euro. Dieser Betrag ist in :currency.',
    'scan' => 'Mit der Banking-App scannen',
    'alt' => 'GiroCode für die Überweisung',
    'skonto' => 'Betrag im Code',
    'skonto_full' => 'Voller Betrag',
    'skonto_with' => 'Mit :percent % Skonto',
    'skonto_until' => 'Skonto bis :date',
    'pay' => 'Zu zahlen: :amount',
    'mark_paid' => 'Als bezahlt markieren',
    'paid' => 'Bezahlt am :date',
    'undo' => 'Rückgängig',
    'failed' => 'Das ging nicht. Bitte noch einmal versuchen.',
];
