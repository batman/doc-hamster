<?php

declare(strict_types=1);

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\NoReceiptReason;
use App\Domain\Banking\Transaction;
use App\Domain\Org\Membership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
| Points 136–143 (A286, A287, A294–A303): dedupe on re-import, accounts per Revolut "Account", refunds, the reasons
| for "kein Beleg nötig". Synthetic data only.
*/
uses(RefreshDatabase::class)->group('db');

const REVOLUT_HEAD = 'Date started (UTC),Date completed (UTC),ID,Type,State,Description,Reference,Payer,Card number,Card label,Card state,Orig currency,Orig amount,Payment currency,Amount,Total amount,Exchange rate,Fee,Fee currency,Balance,Account,International account number,Beneficiary account number,Beneficiary sort code or routing number,Beneficiary IBAN,Beneficiary BIC,Beneficiary name,MCC,Related transaction id,Spend program,Sender account,Sender name,Card references';

/**
 * @param  array<string, string>  $values
 */
function revolutRow(array $values): string
{
    return implode(',', array_map(static fn (string $name): string => $values[$name] ?? '', explode(',', REVOLUT_HEAD)));
}

function revolutUpload(object $test, string $rows, array $extra = []): TestResponse
{
    return $test->post('/bank/import', ['file' => UploadedFile::fake()->createWithContent('transaction-statement_2026.csv', REVOLUT_HEAD."\n".$rows."\n"), ...$extra]);
}

function importedBookings(): Builder
{
    return Transaction::query();
}

beforeEach(function (): void {
    Storage::fake('local');
    $member = Membership::factory()->owner()->create();
    $this->orgId = $member->org_id;
    $this->actingAs($member->user);
});

it('imports a Revolut file into one account per Account and currency, named after them, and adds nothing the second time (A292, A294, A299, 142)', function (): void {
    $rows = implode("\n", [
        revolutRow(['Date completed (UTC)' => '2026-03-02', 'ID' => 'e1', 'Type' => 'CARD_PAYMENT', 'State' => 'COMPLETED', 'Description' => 'Cafe', 'Payment currency' => 'EUR', 'Amount' => '-3.50', 'Total amount' => '-3.50', 'Account' => 'Main']),
        revolutRow(['Date started (UTC)' => '2026-03-03', 'ID' => 'e2', 'Type' => 'TRANSFER', 'State' => 'PENDING', 'Description' => 'Miete', 'Payment currency' => 'EUR', 'Amount' => '-500.00', 'Total amount' => '-500.00', 'Account' => 'Main']),
        revolutRow(['Date completed (UTC)' => '2026-03-04', 'ID' => 'g1', 'Type' => 'CARD_PAYMENT', 'State' => 'COMPLETED', 'Description' => 'Taxi', 'Payment currency' => 'GBP', 'Amount' => '-12.00', 'Total amount' => '-12.00', 'Account' => 'Main']),
    ]);
    revolutUpload($this, $rows)->assertRedirectContains('/bank/accounts/');

    expect(BankAccount::query()->orderBy('name')->pluck('name')->all())->toBe(['Revolut Business EUR', 'Revolut Business GBP'])
        ->and(importedBookings()->count())->toBe(3);

    // A302: the pending one is completed now, gets a reference; its "kein Beleg nötig" stays.
    $rent = importedBookings()->where('booking_id', 'e2')->firstOrFail();
    $rent->forceFill(['no_receipt' => true, 'no_receipt_reason' => 'Privat'])->save();
    $eur = BankAccount::query()->where('name', 'Revolut Business EUR')->firstOrFail();
    $again = str_replace(['PENDING', 'Miete,'], ['COMPLETED', 'Miete,Maerz'], $rows);
    revolutUpload($this, $again, ['account' => $eur->id])->assertRedirectContains('/bank/accounts/');

    expect(importedBookings()->count())->toBe(3)
        ->and($rent->fresh())->bank_state->toBe('completed')->purpose2->toBe('Maerz')->no_receipt->toBeTrue();
});

it('gives a booking imported before without ID its ID and its sign back (A303, 143)', function (): void {
    $account = BankAccount::query()->create(['org_id' => $this->orgId, 'name' => 'Revolut EUR', 'currency' => 'EUR']);
    $old = Transaction::query()->create(['org_id' => $this->orgId, 'bank_account_id' => $account->id, 'booking_date' => '2026-03-02', 'amount_minor' => 350, 'currency' => 'EUR', 'counterparty' => null, 'purpose' => 'Cafe', 'fingerprint' => str_repeat('a', 64)]);

    revolutUpload($this, revolutRow(['Date completed (UTC)' => '2026-03-02', 'ID' => 'e1', 'Type' => 'CARD_PAYMENT', 'Description' => 'Cafe', 'Payment currency' => 'EUR', 'Amount' => '-3.50', 'Total amount' => '-3.50']), ['account' => $account->id])
        ->assertRedirectContains('/bank/accounts/');

    expect(importedBookings()->count())->toBe(1)
        ->and($old->fresh())->booking_id->toBe('e1')->amount_minor->toBe(-350);
});

it('asks for bookings that look like existing ones and shows differing values, then imports as decided (A295, A302)', function (): void {
    $csv = "Buchungstag;Empfaenger;Verwendungszweck;Betrag\n01.02.2026;Muster GmbH;Rechnung 1;-12,30\n";
    $this->post('/bank/import', ['file' => UploadedFile::fake()->createWithContent('a.csv', $csv), 'name' => 'Giro']);
    $this->post('/bank/import/columns', ['date' => 0, 'amount' => 3, 'counterparty' => 1, 'purpose' => 2]);
    $account = BankAccount::query()->firstOrFail();

    // Same day and amount, other text: unclear.
    $this->post('/bank/import', ['file' => UploadedFile::fake()->createWithContent('b.csv', str_replace('Rechnung 1', 'RE 1', $csv)."02.02.2026;Neu;Neu;-1,00\n"), 'account' => $account->id])
        ->assertRedirect(route('bank.import.preview'));
    $this->get('/bank/import/preview')->assertOk()->assertSee('Neu: 1 · Schon vorhanden: 0 · Unklar: 1');

    $this->post('/bank/import/preview', ['decide' => [0 => 'known']])->assertRedirectContains('/bank/accounts/');

    expect(importedBookings()->count())->toBe(2);
});

it('hangs a refund on its original: no own receipt needed (A298)', function (): void {
    revolutUpload($this, implode("\n", [
        revolutRow(['Date completed (UTC)' => '2026-03-02', 'ID' => 'p1', 'Type' => 'CARD_PAYMENT', 'Description' => 'Shop', 'Payment currency' => 'EUR', 'Amount' => '-40.00', 'Total amount' => '-40.00']),
        revolutRow(['Date completed (UTC)' => '2026-03-05', 'ID' => 'r1', 'Type' => 'CARD_REFUND', 'Description' => 'Shop', 'Payment currency' => 'EUR', 'Amount' => '40.00', 'Total amount' => '40.00', 'Related transaction id' => 'p1']),
    ]));
    $refund = importedBookings()->where('booking_id', 'r1')->firstOrFail();

    expect($refund->parent_id)->toBe(importedBookings()->where('booking_id', 'p1')->value('id'))
        ->and($refund->no_receipt)->toBeTrue()
        ->and($refund->status())->toBe(Transaction::NO_RECEIPT);
});

it('marks "kein Beleg nötig" with a listed reason, suggests it for the next booking of that counterparty, keeps a used reason (A286, A287)', function (): void {
    revolutUpload($this, revolutRow(['Date completed (UTC)' => '2026-03-02', 'ID' => 's1', 'Type' => 'TRANSFER', 'Description' => 'Gehalt', 'Payment currency' => 'EUR', 'Amount' => '2000.00', 'Total amount' => '2000.00', 'Payer' => 'Muster GmbH']));
    $salary = NoReceiptReason::ordered()->firstWhere('name', 'Gehalt / Lohn');
    $first = importedBookings()->firstOrFail();

    $this->postJson('/bank/transactions/'.$first->id.'/no-receipt', ['reason_id' => $salary->id])->assertOk();
    expect($first->fresh())->no_receipt->toBeTrue()->no_receipt_reason->toBe('Gehalt / Lohn');

    revolutUpload($this, revolutRow(['Date completed (UTC)' => '2026-04-02', 'ID' => 's2', 'Type' => 'TRANSFER', 'Description' => 'Gehalt', 'Payment currency' => 'EUR', 'Amount' => '2000.00', 'Total amount' => '2000.00', 'Payer' => 'Muster GmbH']),
        ['account' => $first->bank_account_id]);
    expect(importedBookings()->where('booking_id', 's2')->value('suggested_reason_id'))->toBe($salary->id);

    $this->post('/bank/reasons/'.$salary->id.'/delete')->assertSessionHasErrors('reason');
    $this->postJson('/bank/transactions/'.$first->id.'/no-receipt', ['reason' => 'Sonstwas'])->assertOk();
    expect($first->fresh()->no_receipt_reason)->toBe('Sonstwas');
});
