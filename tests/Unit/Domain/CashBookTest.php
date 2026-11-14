<?php

declare(strict_types=1);

use App\Domain\Banking\CashBook;

/*
| Point 190: the running balance of a cash box – opening balance plus every entry up to it.
*/

it('adds each entry to the opening balance in booking order', function (): void {
    expect(CashBook::running(10000, ['a' => 5000, 'b' => -1250, 'c' => -750]))->toBe(['a' => 15000, 'b' => 13750, 'c' => 13000]);
});

it('goes below zero and starts from nothing', function (): void {
    expect(CashBook::running(0, ['a' => -300, 'b' => 100]))->toBe(['a' => -300, 'b' => -200])
        ->and(CashBook::running(500, []))->toBe([]);
});
