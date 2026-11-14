<?php

declare(strict_types=1);

use App\Domain\ReceiptPack\IbanMask;
use App\Domain\ReceiptPack\PackBooking;
use App\Domain\ReceiptPack\PackFigures;
use App\Domain\ReceiptPack\PackPeriod;
use App\Domain\ReceiptPack\PackPlan;
use App\Domain\ReceiptPack\PackReceipt;
use App\Domain\ReceiptPack\PackSection;
use Carbon\CarbonImmutable;

function packBooking(int $minor, bool $receipt = false, bool $none = false, string $currency = 'EUR'): PackBooking
{
    return new PackBooking('b'.random_int(1, PHP_INT_MAX), CarbonImmutable::parse('2026-07-01'), $minor, $currency, 'Muster GmbH', '',
        [], $receipt ? [new PackReceipt('d1', 'Rechnung')] : [], $none);
}

it('masks an IBAN to country, check digits and the last four (point 211.1)', function (): void {
    expect(IbanMask::mask('DE00 0000 0000 0000 0000 00'))->toBe('DE00 **** **** **** **00 00')
        ->and(IbanMask::mask('de12345678901234567890'))->toBe('DE12 **** **** **** **78 90')
        ->and(IbanMask::mask(null))->toBe('')
        ->and(IbanMask::mask('DE12'))->toBe('');
});

it('sums expenses and income per currency and lists the missing receipts', function (): void {
    $missing = packBooking(-1250);
    $figures = PackFigures::of([
        packBooking(-1000, receipt: true),
        $missing,
        packBooking(-500, none: true),
        packBooking(20000, receipt: true),
        packBooking(-700, currency: 'USD', receipt: true),
    ]);

    expect($figures->count)->toBe(5)
        ->and($figures->withReceipt)->toBe(3)
        ->and($figures->noReceipt)->toBe(1)
        ->and($figures->expenses)->toBe(['EUR' => 2750, 'USD' => 700])
        ->and($figures->income)->toBe(['EUR' => 20000])
        ->and($figures->missing)->toBe([$missing])
        ->and($figures->missingCount())->toBe(1);
});

it('adds all accounts up for the cover', function (): void {
    $a = new PackSection('a', 'Giro', '', 'business', 'EUR', [packBooking(-100), packBooking(300, receipt: true)]);
    $b = new PackSection('b', 'Kasse', '', 'cash', 'EUR', [packBooking(-50, receipt: true)]);

    $total = PackFigures::total($a, $b);

    expect($total->count)->toBe(3)->and($total->expenses)->toBe(['EUR' => 150])->and($total->income)->toBe(['EUR' => 300])->and($total->missingCount())->toBe(1);
});

it('numbers the overview pages: cover, overview, one page per booking plus extra pictures, loose, missing', function (): void {
    // Two accounts: 3 bookings (pictures 1, 0, 3) and 1 booking (2 pictures); one loose document with 1 picture; 2 missing.
    $plan = new PackPlan([[1, 0, 3], [2]], [1], 2);

    expect($plan->overview)->toHaveCount(1)
        ->and($plan->bookingPage)->toBe([[3, 4, 5], [8]])
        ->and($plan->number)->toBe([[1, 2, 3], [4]])
        ->and($plan->loosePage)->toBe([10])
        ->and($plan->missingPage)->toBe(11)
        ->and($plan->pages)->toBe(11);
});

it('spreads a long overview over several pages and repeats the account head', function (): void {
    $plan = new PackPlan([array_fill(0, 50, 1)], [], 0);

    // 3 head rows + 37 bookings on page 1, the head again + 13 bookings on page 2.
    expect($plan->overview)->toHaveCount(2)
        ->and(count(array_filter($plan->overview[0], fn (array $r): bool => $r[0] === 'row')))->toBe(37)
        ->and($plan->overview[1][0])->toBe(['head', 0])
        ->and($plan->bookingPage[0][0])->toBe(4)
        ->and($plan->bookingPage[0][49])->toBe(53)
        ->and($plan->pages)->toBe(54);
});

it('gives the missing list as many pages as it needs', function (): void {
    $plan = new PackPlan([], [], PackPlan::MISSING_ROWS + 1);

    expect($plan->missingPage)->toBe(3)->and($plan->missingPages)->toBe(2)->and($plan->pages)->toBe(4);
});

it('names the period for the file (A379)', function (): void {
    expect(PackPeriod::of('2026-07-01', '2026-09-30')->label())->toBe('2026-Q3')
        ->and(PackPeriod::of('2026-07-01', '2026-07-31')->label())->toBe('2026-07')
        ->and(PackPeriod::of('2026-01-01', '2026-12-31')->label())->toBe('2026')
        ->and(PackPeriod::of('2026-07-01', '2026-09-15')->label())->toBe('2026-07-01_2026-09-15')
        ->and(PackPeriod::fileName('2026-Q3', 2))->toBe('Belegpaket_2026-Q3_v2.pdf');
});
