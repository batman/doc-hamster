<?php

declare(strict_types=1);

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\BankCalendar;
use App\Domain\Banking\Transaction;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
| 159, A332–A334: the account window's calendar (months only between the first and last booking, day sums, open days)
| and the period filter a calendar day sets.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    $this->actingAs($this->admin->user);
    app(CurrentOrg::class)->set($this->admin);
    $this->account = BankAccount::query()->create(['name' => 'Giro', 'currency' => 'EUR']);
});

function calendarBooking(string $date, int $amount, array $more = []): Transaction
{
    return Transaction::query()->create(['bank_account_id' => test()->account->id, 'booking_date' => $date, 'amount_minor' => $amount, 'currency' => 'EUR',
        'counterparty' => 'Muster GmbH', 'fingerprint' => Str::random(24), ...$more]);
}

it('pages only between the first and the last month with bookings (159.2)', function (): void {
    calendarBooking('2026-02-10', -500);
    calendarBooking('2026-05-03', 900);
    $range = BankCalendar::range(Transaction::query()->where('bank_account_id', $this->account->id));
    $now = CarbonImmutable::parse('2026-11-14');

    expect($range['first']->format('Y-m'))->toBe('2026-02')->and($range['last']->format('Y-m'))->toBe('2026-05')
        ->and(BankCalendar::month(null, $range, $now)->format('Y-m'))->toBe('2026-05')
        ->and(BankCalendar::month('2026-03', $range, $now)->format('Y-m'))->toBe('2026-03')
        ->and(BankCalendar::month('2025-12', $range, $now)->format('Y-m'))->toBe('2026-02')
        ->and(BankCalendar::month('2026-04', null, $now)->format('Y-m'))->toBe('2026-04')
        ->and(BankCalendar::range(Transaction::query()->where('amount_minor', 1)))->toBeNull();
});

it('sums each day, keeps three bookings and marks days with an open booking (A332, A334)', function (): void {
    foreach ([-100, -200, 300, -400] as $amount) {
        calendarBooking('2026-03-02', $amount, ['no_receipt' => true]);
    }
    calendarBooking('2026-03-05', -50);
    calendarBooking('2026-03-05', -70, ['currency' => 'USD']);
    $days = BankCalendar::days(Transaction::query()->withStatusCounts()->orderBy('booking_date')->get(), 'EUR');

    expect(array_keys($days))->toBe([2, 5])
        ->and($days[2])->sum->toBe(-400)->more->toBe(1)->open->toBeFalse()
        ->and($days[2]['entries'])->toHaveCount(3)
        ->and($days[5])->sum->toBe(-50)->open->toBeTrue();
});

it('shows the calendar month with filters and a day as the list of just that day (A333)', function (): void {
    calendarBooking('2026-03-02', -100);
    calendarBooking('2026-03-09', 250);
    calendarBooking('2026-04-01', -30, ['no_receipt' => true]);

    $this->get(route('bank.account', [$this->account->id, 'view' => 'calendar', 'month' => '2026-03', 'flow' => 'in']))->assertOk()
        ->assertViewHas('calendar', static fn (array $c): bool => $c['month']->format('Y-m') === '2026-03' && array_keys($c['days']) === [9]);
    $this->get(route('bank.account', [$this->account->id, 'view' => 'calendar', 'status' => 'none']))->assertOk()
        ->assertViewHas('calendar', static fn (array $c): bool => $c['month']->format('Y-m') === '2026-04');
    $this->get(route('bank.account', [$this->account->id, 'from' => '2026-03-09', 'to' => '2026-03-09']))->assertOk()
        ->assertViewHas('transactions', static fn ($list): bool => $list->pluck('amount_minor')->all() === [250])
        ->assertSee('09.03.2026');
});
