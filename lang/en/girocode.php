<?php

declare(strict_types=1);

// GiroCode card in the detail window (point 120, A237-A239, A247, A249, A250).
return [
    'title' => 'GiroCode',
    'fields' => [
        'payee' => 'Payee',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'amount' => 'Amount',
        'reference' => 'Payment reference',
    ],
    'reference_invoice' => 'Invoice :number',
    'reference_customer' => 'Customer :number',
    'reference_hint' => 'Leave empty = from invoice and customer number',
    'empty' => 'missing',
    'suggested' => 'suggested',
    'unsure' => 'unsure',
    'other_amounts' => 'Other amounts on the document',
    'take_amount' => 'Use as amount: :value',
    'swap' => 'The document says “:original” – that is no valid IBAN. Probably meant:',
    'take_iban' => 'Use as IBAN: :value',
    'missing' => 'The GiroCode still needs: :fields',
    'not_euro' => 'A GiroCode works only for euros. This amount is in :currency.',
    'scan' => 'Scan with your banking app',
    'alt' => 'GiroCode for the transfer',
    'skonto' => 'Amount in the code',
    'skonto_full' => 'Full amount',
    'skonto_with' => 'With :percent % discount',
    'skonto_until' => 'Discount until :date',
    'pay' => 'To pay: :amount',
    'mark_paid' => 'Mark as paid',
    'paid' => 'Paid on :date',
    'undo' => 'Undo',
    'failed' => 'That did not work. Please try again.',
];
