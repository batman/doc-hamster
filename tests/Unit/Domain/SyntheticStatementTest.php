<?php

declare(strict_types=1);

use App\Domain\Banking\CsvStatement;
use App\Domain\Banking\StatementImport;

// Offer 1 of upstream issue #67: a synthetic German statement that exercises
// the whole import path (delimiter, header below an account line, umlauts,
// thousands separators, a multi-line purpose, a duplicate pair, a footer).
beforeEach(function (): void {
    $content = file_get_contents(__DIR__.'/../../fixtures/banking/synthetic-de-01.csv');

    $this->statement = CsvStatement::parse((string) $content);
});

it('detects the semicolon and the header below the account line', function (): void {
    expect($this->statement->header)->toBe(['Buchungstag', 'Empfänger', 'Verwendungszweck', 'Betrag'])
        ->and($this->statement->rows)->toHaveCount(8);
});

it('guesses all four columns', function (): void {
    expect($this->statement->guessColumns())->toBe(['date' => 0, 'amount' => 3, 'counterparty' => 1, 'purpose' => 2, 'iban' => null]);
});

it('reads seven lines and counts the footer as unreadable', function (): void {
    $result = StatementImport::read($this->statement, $this->statement->guessColumns());

    expect($result['lines'])->toHaveCount(7)
        ->and($result['unreadable'])->toBe(1);
});

it('spot-checks a minor value, a date and the multi-line purpose', function (): void {
    $result = StatementImport::read($this->statement, $this->statement->guessColumns());

    expect($result['lines'][1])->toMatchArray(['booking_date' => '2026-01-03', 'amount_minor' => 123456])
        ->and($result['lines'][4])->toMatchArray([
            'booking_date' => '2026-01-06',
            'amount_minor' => -8900,
            'purpose' => 'Abschlag Januar Kundennummer 12345',
        ]);
});

it('gives the duplicate pair different fingerprints, stable across re-reads', function (): void {
    $columns = $this->statement->guessColumns();
    $first = StatementImport::read($this->statement, $columns);
    $second = StatementImport::read($this->statement, $columns);

    expect($first['lines'][2]['fingerprint'])->not->toBe($first['lines'][3]['fingerprint'])
        ->and(array_column($second['lines'], 'fingerprint'))->toBe(array_column($first['lines'], 'fingerprint'));
});
