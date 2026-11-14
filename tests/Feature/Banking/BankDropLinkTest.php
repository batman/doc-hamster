<?php

declare(strict_types=1);

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| Point 160, A337–A339: linking by drop (also from another browser tab) – same organisation only, the permission,
| n:m, and "Rückgängig" removing only the links the drop made.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->actingAs($this->admin->user);
    app(CurrentOrg::class)->set($this->admin);
    $this->account = BankAccount::query()->create(['name' => 'Giro', 'currency' => 'EUR']);
});

function dropBooking(int $amount = -1000): Transaction
{
    return Transaction::query()->create(['bank_account_id' => test()->account->id, 'booking_date' => '2026-11-02', 'amount_minor' => $amount,
        'currency' => 'EUR', 'counterparty' => 'Muster GmbH', 'fingerprint' => Str::random(24)]);
}

function dropDocument(string $title = 'rechnung.pdf'): Document
{
    return Document::query()->create(['uploaded_by' => test()->admin->user_id, 'title' => $title]);
}

it('links dropped documents n:m and undoes only the new links (160.2, A338, A339)', function (): void {
    $booking = dropBooking();
    $other = dropBooking(-500);
    $old = dropDocument('alt.pdf');
    $a = dropDocument('a.pdf');
    $b = dropDocument('b.pdf');
    $this->postJson("/bank/transactions/{$booking->id}/link", ['ids' => [$old->id]])->assertOk();

    $answer = $this->postJson("/bank/transactions/{$booking->id}/link", ['ids' => [$old->id, $a->id, $b->id]])->assertOk()
        ->assertJson(['message' => '3 Dokumente verknüpft.'])->json('undo');
    expect($answer['url'])->toBe(route('bank.unlink', $booking->id))
        ->and($answer['ids'])->toEqualCanonicalizing([$a->id, $b->id]);
    // The same document on a second booking (n:m).
    $this->postJson("/bank/transactions/{$other->id}/link", ['ids' => [$a->id]])->assertOk()->assertJson(['message' => 'Verknüpft.']);

    $this->postJson($answer['url'], ['ids' => $answer['ids']])->assertOk()->assertJson(['message' => '2 Verknüpfungen gelöst.']);
    expect(TransactionMatch::query()->where('transaction_id', $booking->id)->pluck('document_id')->all())->toBe([$old->id])
        ->and(TransactionMatch::query()->where('transaction_id', $other->id)->pluck('document_id')->all())->toBe([$a->id]);
});

it('never links across organisations, also not by ids from another tab (160.4)', function (): void {
    $booking = dropBooking();
    $mine = dropDocument();
    $stranger = Membership::factory()->owner()->create();
    app(CurrentOrg::class)->set($stranger);
    $foreign = Document::query()->create(['uploaded_by' => $stranger->user_id, 'title' => 'fremd.pdf']);
    $foreignAccount = BankAccount::query()->create(['name' => 'Fremd', 'currency' => 'EUR']);
    $foreignBooking = Transaction::query()->create(['bank_account_id' => $foreignAccount->id,
        'booking_date' => '2026-11-02', 'amount_minor' => -1000, 'currency' => 'EUR', 'fingerprint' => Str::random(24)]);
    app(CurrentOrg::class)->set($this->admin);

    $this->postJson("/bank/transactions/{$booking->id}/link", ['ids' => [$foreign->id]])->assertStatus(422);
    $this->postJson("/bank/transactions/{$booking->id}/link", ['ids' => [$foreign->id, $mine->id]])->assertOk()->assertJson(['undo' => ['ids' => [$mine->id]]]);
    $this->postJson("/bank/transactions/{$foreignBooking->id}/link", ['ids' => [$mine->id]])->assertNotFound();
    $this->postJson("/bank/transactions/{$foreignBooking->id}/unlink", ['ids' => [$mine->id]])->assertNotFound();

    expect(TransactionMatch::query()->withoutGlobalScope(OrgScope::class)->pluck('document_id')->all())->toBe([$mine->id]);
});

it('needs banking.import to link or undo by drop (160)', function (): void {
    $booking = dropBooking();
    $doc = dropDocument();
    $this->postJson("/bank/transactions/{$booking->id}/link", ['ids' => [$doc->id]])->assertOk();

    $advisor = Membership::factory()->accountant()->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($advisor->user);
    $this->postJson("/bank/transactions/{$booking->id}/link", ['ids' => [$doc->id]])->assertForbidden();
    $this->postJson("/bank/transactions/{$booking->id}/unlink", ['ids' => [$doc->id]])->assertForbidden();
    expect(TransactionMatch::query()->count())->toBe(1);
});
