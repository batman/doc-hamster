<?php

declare(strict_types=1);

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Org\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Points 189, 190: accounts set up by hand before any import, the IBAN that sends a later statement into them, and
| the Bargeldkasse with its running balance. Synthetic data only (the IBAN is the common documentation example).
*/
uses(RefreshDatabase::class)->group('db');

const HAND_IBAN = 'DE89 3704 0044 0532 0130 00';

beforeEach(function (): void {
    Storage::fake('local');
    $member = Membership::factory()->owner()->create();
    $this->orgId = $member->org_id;
    $this->actingAs($member->user);
});

/**
 * @return array<string, int>
 */
function cashBalances(BankAccount $account): array
{
    return Transaction::query()->where('bank_account_id', $account->id)->orderBy('booking_date')->orderBy('id')
        ->pluck('balance_minor', 'purpose')->map(static fn ($b): int => (int) $b)->all();
}

it('imports a statement with the IBAN of an account set up by hand into that account', function (): void {
    $this->post('/bank/accounts', ['name' => 'Hausbank', 'kind' => 'private', 'iban' => HAND_IBAN, 'currency' => 'EUR'])->assertRedirectContains('/bank/accounts/');
    $account = BankAccount::query()->sole();
    expect($account)->kind->toBe('private')->iban->toBe('DE89370400440532013000');

    // The file itself carries no IBAN: the one typed into the upload form finds the account.
    $csv = "Buchungstag;Empfaenger;Verwendungszweck;Betrag\n01.02.2026;Muster GmbH;Rechnung 1;-12,30\n02.02.2026;Cafe;Kaffee;-3,50\n";
    $response = $this->post('/bank/import', ['file' => UploadedFile::fake()->createWithContent('auszug.csv', $csv), 'name' => 'Neues Konto', 'iban' => HAND_IBAN])->assertRedirect();
    if ($response->headers->get('Location') === route('bank.import.columns')) {
        $this->post('/bank/import/columns', ['date' => 0, 'amount' => 3, 'counterparty' => 1, 'purpose' => 2])->assertRedirectContains('/bank/accounts/'.$account->id);
    }

    expect(BankAccount::query()->count())->toBe(1)
        ->and(Transaction::query()->where('bank_account_id', $account->id)->count())->toBe(2);
});

it('refuses a second account with the same IBAN', function (): void {
    $this->post('/bank/accounts', ['name' => 'A', 'kind' => 'business', 'iban' => HAND_IBAN])->assertRedirect();
    $this->post('/bank/accounts', ['name' => 'B', 'kind' => 'business', 'iban' => HAND_IBAN])->assertSessionHasErrors('iban');

    expect(BankAccount::query()->count())->toBe(1);
});

it('keeps the running balance of a cash box through adding, changing and deleting entries', function (): void {
    $this->post('/bank/accounts', ['name' => 'Kasse', 'kind' => 'cash', 'opening' => '100,00', 'iban' => HAND_IBAN])->assertRedirect();
    $cash = BankAccount::query()->sole();
    expect($cash)->kind->toBe('cash')->iban->toBeNull()->opening_balance_minor->toBe(10000);

    $this->post("/bank/accounts/{$cash->id}/entries", ['date' => '2026-11-02', 'flow' => 'out', 'amount' => '12,50', 'text' => 'Porto'])->assertRedirect();
    $this->post("/bank/accounts/{$cash->id}/entries", ['date' => '2026-11-01', 'flow' => 'in', 'amount' => '50', 'text' => 'Einlage'])->assertRedirect();
    $this->post("/bank/accounts/{$cash->id}/entries", ['date' => '2026-11-02', 'flow' => 'out', 'amount' => '7,50', 'text' => 'Kaffee'])->assertRedirect();

    expect(cashBalances($cash))->toBe(['Einlage' => 15000, 'Porto' => 13750, 'Kaffee' => 13000])
        ->and(Transaction::query()->where('purpose', 'Porto')->sole())->source->toBe('manual')->amount_minor->toBe(-1250);

    $porto = Transaction::query()->where('purpose', 'Porto')->sole();
    $this->post("/bank/entries/{$porto->id}", ['date' => '2026-11-03', 'flow' => 'out', 'amount' => '20,00', 'text' => 'Porto'])->assertRedirect();
    expect(cashBalances($cash))->toBe(['Einlage' => 15000, 'Kaffee' => 14250, 'Porto' => 12250]);

    $this->post("/bank/entries/{$porto->id}/delete")->assertRedirect();
    expect(cashBalances($cash))->toBe(['Einlage' => 15000, 'Kaffee' => 14250]);

    $this->get("/bank/accounts/{$cash->id}")->assertOk()->assertSee('142,50 EUR');
});

it('never edits or deletes an imported booking', function (): void {
    $this->post('/bank/accounts', ['name' => 'Giro', 'kind' => 'business'])->assertRedirect();
    $account = BankAccount::query()->sole();
    $booking = Transaction::query()->create(['org_id' => $this->orgId, 'bank_account_id' => $account->id, 'booking_date' => '2026-03-02', 'amount_minor' => -350, 'currency' => 'EUR', 'purpose' => 'Cafe', 'fingerprint' => str_repeat('b', 64)]);

    $this->post("/bank/entries/{$booking->id}/delete")->assertNotFound();
    expect($booking->fresh())->not->toBeNull();
});
