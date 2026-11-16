<?php

declare(strict_types=1);

use App\Domain\Ocr\WordBlock;

/*
| Point 242, A428-A430: OCR words taken as one value – cleaning per type, growing a word to the whole IBAN or amount,
| the combined box. Synthetic IBANs only (check digits computed here).
*/

function syntheticIban(string $country, string $bban): string
{
    $digits = '';
    foreach (str_split($bban.$country.'00') as $char) {
        $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
    }
    $rest = 0;
    foreach (str_split($digits, 7) as $chunk) {
        $rest = (int) ($rest.$chunk) % 97;
    }

    return $country.str_pad((string) (98 - $rest), 2, '0', STR_PAD_LEFT).$bban;
}

/**
 * One line of words, left to right, each 0.05 wide.
 *
 * @return list<array<string, mixed>>
 */
function lineOf(string ...$texts): array
{
    $out = [];
    foreach ($texts as $i => $t) {
        $out[] = ['t' => $t, 'x' => 0.1 + $i * 0.06, 'y' => 0.5, 'w' => 0.05, 'h' => 0.02, 'c' => 95, 'l' => 3, 'b' => 0];
    }

    return $out;
}

it('cleans an IBAN block: no spaces, checked, OCR slips repaired', function (): void {
    $iban = syntheticIban('DE', '370400440532013000');
    expect(WordBlock::clean('iban', implode(' ', str_split($iban, 4))))->toBe($iban)
        ->and(WordBlock::clean('iban', 'IBAN: '.strtolower($iban)))->toBe($iban)
        ->and(WordBlock::clean('iban', substr($iban, 0, 6).'O'.substr($iban, 7)))->toBe($iban);
});

it('cleans an amount block to a number', function (string $text, string $clean): void {
    expect(WordBlock::clean('amount', $text))->toBe($clean);
})->with([
    'space thousands' => ['1 234,56', '1.234,56 €'],
    'with label' => ['Summe 1 234,56 €', '1.234,56 €'],
    'plain' => ['12,50 €', '12,50 €'],
]);

it('keeps other types as they are, with single spaces', function (): void {
    expect(WordBlock::clean('text', "Muster  GmbH\nBerlin"))->toBe('Muster GmbH Berlin')
        ->and(WordBlock::clean('date', '12. 03. 2026'))->toBe('12.03.2026');
});

it('grows one marked group to the whole IBAN of its line', function (): void {
    $iban = syntheticIban('CY', '00200128000000120052'.'7600');
    $words = lineOf(...['IBAN:', ...str_split($iban, 4), 'BIC']);
    $grown = WordBlock::extend('iban', $words, [1]);
    expect($grown)->not->toBeNull()
        ->and($grown['words'])->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($grown['text'])->toBe($iban);
});

it('grows a spaced amount and leaves a complete one alone', function (): void {
    $words = lineOf('Summe', '1', '234,56', '€', 'brutto');
    $grown = WordBlock::extend('amount', $words, [1]);
    expect($grown['words'] ?? null)->toBe([1, 2, 3])
        ->and($grown['text'] ?? null)->toBe('1.234,56 €')
        ->and(WordBlock::extend('amount', lineOf('12,50', '€'), [0]))->toBeNull()
        ->and(WordBlock::extend('text', $words, [1]))->toBeNull();
});

it('only takes words of the same line', function (): void {
    $iban = syntheticIban('DE', '370400440532013000');
    $words = lineOf(...str_split($iban, 4));
    $words[5]['l'] = 4; // the last group is on the next line
    expect(WordBlock::extend('iban', $words, [0]))->toBeNull();
});

it('gives the box around all words', function (): void {
    expect(WordBlock::box(lineOf('a', 'b', 'c')))->toBe(['x' => 0.1, 'y' => 0.5, 'w' => 0.17, 'h' => 0.02])
        ->and(WordBlock::box([]))->toBeNull();
});
