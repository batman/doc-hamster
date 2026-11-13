<?php

declare(strict_types=1);

use App\Domain\Banking\MatchScore;

/*
| A268: the score of a booking against a document (pure).
*/

const PAYMENT = ['date' => '2026-11-17', 'amount' => -40460, 'counterparty' => 'Muster Handwerk GmbH', 'purpose' => 'Rechnung RE-2026-0042 Kd 4711', 'iban' => 'DE02120300000000202051'];

it('gives a full match 100 with every reason', function (): void {
    $doc = ['amount' => 40460, 'invoice_date' => '2026-11-03', 'due_date' => '2026-11-17', 'iban' => 'DE02120300000000202051', 'sender' => 'Muster Handwerk GmbH', 'invoice_number' => 'RE-2026-0042'];

    $result = MatchScore::score(PAYMENT, $doc);

    expect($result['score'])->toBe(100)
        ->and($result['reasons'])->toBe(['amount' => 40, 'date' => 15, 'iban' => 25, 'invoice_number' => 25, 'sender' => 20]);
});

it('compares amounts by their absolute value, ±1 % gives half', function (): void {
    expect(MatchScore::score(PAYMENT, ['amount' => 40460])['reasons'])->toBe(['amount' => 40])
        ->and(MatchScore::score(['date' => '2026-11-17', 'amount' => 40460], ['amount' => 40460])['reasons'])->toBe(['amount' => 40])
        ->and(MatchScore::score(PAYMENT, ['amount' => 40100])['reasons'])->toBe(['amount' => 20])
        ->and(MatchScore::score(PAYMENT, ['amount' => 30000])['score'])->toBe(0)
        ->and(MatchScore::score(PAYMENT, ['amounts' => [100, 40460]])['reasons'])->toBe(['amount' => 30]);
});

it('scores the date by due date, invoice date or upload day', function (): void {
    $at = static fn (array $doc): int => MatchScore::score(['date' => '2026-11-17', 'amount' => -1], $doc)['score'];

    expect($at(['due_date' => '2026-11-15']))->toBe(15)
        ->and($at(['invoice_date' => '2026-11-10']))->toBe(10)
        ->and($at(['invoice_date' => '2026-10-10']))->toBe(5)
        ->and($at(['invoice_date' => '2026-12-10']))->toBe(0)
        ->and($at(['uploaded' => '2026-11-17']))->toBe(15)
        ->and($at(['uploaded' => '2026-11-19']))->toBe(8)
        ->and($at(['uploaded' => '2026-11-30']))->toBe(0);
});

it('finds IBAN and invoice number in the purpose, the sender by its words', function (): void {
    $tx = ['date' => '2026-01-01', 'amount' => -1, 'counterparty' => 'MUSTER HANDWERK', 'purpose' => 'IBAN DE02 1203 0000 0000 2020 51 Rg. re2026 0042'];

    expect(MatchScore::score($tx, ['iban' => 'DE02120300000000202051'])['reasons'])->toBe(['iban' => 25])
        ->and(MatchScore::score($tx, ['invoice_number' => 'RE-2026-0042'])['reasons'])->toBe(['invoice_number' => 25])
        ->and(MatchScore::score($tx, ['invoice_number' => '42'])['score'])->toBe(0)
        ->and(MatchScore::score($tx, ['sender' => 'Muster Handwerk GmbH & Co. KG'])['reasons'])->toBe(['sender' => 20])
        ->and(MatchScore::score($tx, ['sender' => 'Ganz Anders AG'])['score'])->toBe(0);
});

it('scores only pairs worth it', function (): void {
    expect(MatchScore::worthScoring(PAYMENT, ['amount' => 40400]))->toBeTrue()
        ->and(MatchScore::worthScoring(PAYMENT, ['amount' => 10000]))->toBeFalse()
        ->and(MatchScore::worthScoring(PAYMENT, ['amount' => 10000, 'iban' => 'DE02120300000000202051']))->toBeTrue()
        ->and(MatchScore::worthScoring(PAYMENT, ['uploaded' => '2026-11-18']))->toBeTrue()
        ->and(MatchScore::worthScoring(PAYMENT, ['uploaded' => '2026-01-18']))->toBeFalse();
});
