<?php

declare(strict_types=1);

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Matcher;
use App\Domain\Banking\StandingDocument;
use App\Domain\Banking\StandingDocuments;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Points 145–148, A318–A326: candidates without documents linked elsewhere, Dauerbeleg rules (past, future, other
| amount, direction, end), the interval guess, the booking note in the search and counts that follow the search.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->orgId = $this->admin->org_id;
    $this->actingAs($this->admin->user);
    app(CurrentOrg::class)->set($this->admin);
    $this->account = BankAccount::query()->create(['name' => 'Giro', 'currency' => 'EUR']);
});

function standingBooking(string $date, int $amount, string $name = 'Muster Versicherung AG', ?string $iban = 'DE02120300000000202051', array $more = []): Transaction
{
    return Transaction::query()->create(['bank_account_id' => test()->account->id, 'booking_date' => $date, 'amount_minor' => $amount, 'currency' => 'EUR',
        'counterparty' => $name, 'counterparty_iban' => $iban, 'fingerprint' => Str::random(24), ...$more]);
}

function standingDocument(string $title = 'vertrag.pdf'): Document
{
    return Document::query()->create(['uploaded_by' => test()->admin->user_id, 'title' => $title]);
}

function standingState(Transaction $t, Document $d): ?string
{
    return TransactionMatch::query()->where('transaction_id', $t->id)->where('document_id', $d->id)->value('state');
}

it('hides documents linked to another booking from the candidates unless asked (145, A318)', function (): void {
    $first = standingBooking('2026-01-05', -1000);
    $second = standingBooking('2026-02-05', -1000);
    $doc = standingDocument();
    $this->postJson("/bank/transactions/{$first->id}/match", ['document_id' => $doc->id])->assertOk();

    $ids = static fn (bool $with): array => collect(app(Matcher::class)->candidates($second, Document::query(), 'vertrag', withLinked: $with))->pluck('document.id')->all();
    expect($ids(false))->toBe([])->and($ids(true))->toBe([$doc->id]);
});

it('links a Dauerbeleg to every equal booking, suggests other amounts and keeps the direction (A322–A324)', function (): void {
    $jan = standingBooking('2026-01-05', -1000);
    $feb = standingBooking('2026-02-05', -1000);
    $mar = standingBooking('2026-03-05', -1000);
    $apr = standingBooking('2026-04-05', -1200);
    $refund = standingBooking('2026-04-10', 1000);
    $other = standingBooking('2026-02-05', -1000, 'Andere GmbH', 'DE89370400440532013000');
    $taken = standingBooking('2026-03-20', -1000);
    $own = standingDocument('eigene.pdf');
    $this->postJson("/bank/transactions/{$taken->id}/match", ['document_id' => $own->id])->assertOk();
    $doc = standingDocument();

    $this->postJson("/bank/transactions/{$jan->id}/match", ['document_id' => $doc->id, 'standing' => 1])->assertOk();

    expect([standingState($jan, $doc), standingState($feb, $doc), standingState($mar, $doc), standingState($apr, $doc), standingState($refund, $doc), standingState($other, $doc), standingState($taken, $doc)])
        ->toBe(['confirmed', 'confirmed', 'confirmed', 'suggested', null, null, null]);
    $rule = StandingDocument::query()->sole();
    expect([$rule->amount_minor, $rule->direction, $rule->interval])->toBe([1000, 'out', 'monthly']);

    // A323: confirming the other amount moves the rule's amount.
    $this->postJson("/bank/transactions/{$apr->id}/confirm", ['document_id' => $doc->id])->assertOk();
    expect($rule->fresh()->amount_minor)->toBe(1200);
});

it('links future imports, suggests other amounts and stops after the end (A322, A323, A325)', function (): void {
    $jan = standingBooking('2026-01-05', 5000, 'Mieter Max Mustermann', null);
    $doc = standingDocument('mietvertrag.pdf');
    $this->postJson("/bank/transactions/{$jan->id}/match", ['document_id' => $doc->id, 'standing' => 1])->assertOk();
    $rule = StandingDocument::query()->sole();
    expect([$rule->counterparty_iban, $rule->counterparty_name, $rule->direction])->toBe([null, 'mieter max mustermann', 'in']);

    $feb = standingBooking('2026-02-05', 5000, 'MIETER Max  Mustermann', null);
    $more = standingBooking('2026-02-06', 5500, 'Mieter Max Mustermann', null);
    expect(app(StandingDocuments::class)->afterImport($this->orgId, [$feb->id, $more->id]))->toBe(2)
        ->and([standingState($feb, $doc), standingState($more, $doc)])->toBe(['confirmed', 'suggested']);

    $this->postJson("/bank/standing/{$rule->id}/end")->assertOk();
    $mar = standingBooking('2026-03-05', 5000, 'Mieter Max Mustermann', null);
    expect(app(StandingDocuments::class)->afterImport($this->orgId, [$mar->id]))->toBe(0)
        ->and(standingState($jan, $doc))->toBe('confirmed');
});

it('guesses the interval from the booking days', function (array $days, ?string $interval): void {
    expect(StandingDocuments::interval(array_map(static fn (string $d): Carbon => Carbon::parse($d), $days)))->toBe($interval);
})->with([
    'one' => [['2026-01-01'], null],
    'monthly' => [['2026-01-01', '2026-01-31', '2026-03-02'], 'monthly'],
    'quarterly' => [['2026-01-01', '2026-04-01', '2026-07-01'], 'quarterly'],
    'yearly' => [['2025-01-01', '2026-01-01'], 'yearly'],
    'irregular' => [['2026-01-01', '2026-01-10'], 'irregular'],
]);

it('finds the note in the account search and the counts follow the search (147, A320, A326)', function (): void {
    standingBooking('2026-01-05', -1000, 'Cafe', null, ['note' => 'Teamfrühstück']);
    standingBooking('2026-01-06', -500, 'Cafe', null);
    standingBooking('2026-01-07', -700, 'Bäckerei', null, ['no_receipt' => true]);

    $this->get("/bank/accounts/{$this->account->id}?q=teamfr")->assertOk()
        ->assertViewHas('statusCounts', ['open' => 1, 'suggested' => 0, 'linked' => 0, 'none' => 0]);
    $this->get("/bank/accounts/{$this->account->id}?q=Cafe")->assertViewHas('statusCounts', ['open' => 2, 'suggested' => 0, 'linked' => 0, 'none' => 0]);
    $this->get("/bank/accounts/{$this->account->id}")->assertViewHas('statusCounts', ['open' => 2, 'suggested' => 0, 'linked' => 0, 'none' => 1]);

    $booking = Transaction::query()->where('counterparty', 'Bäckerei')->sole();
    $this->postJson("/bank/transactions/{$booking->id}/note", ['field' => 'note', 'value' => 'Brötchen'])->assertOk()->assertJson(['value' => 'Brötchen']);
    expect(Transaction::query()->withoutGlobalScope(OrgScope::class)->whereKey($booking->id)->value('note'))->toBe('Brötchen');
});
