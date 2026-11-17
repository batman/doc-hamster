<?php

declare(strict_types=1);

use App\Domain\Api\AccessKey;
use App\Domain\Assistant\AssistantAccess;
use App\Domain\Audit\AuditLogEntry;
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
| D-040: the interface (api/v1) opens only with a person's access key; "Nur lesen" reads, "Lesen + Verknüpfen" also
| links; only the key's hash is stored; another organisation's data stays out of reach.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->owner = Membership::factory()->owner()->create();
    app(CurrentOrg::class)->set($this->owner);
    // D-040: the owner opened "Zugang für Assistenten".
    AssistantAccess::save($this->owner->organization, true);
    $this->account = BankAccount::query()->create(['name' => 'Giro', 'currency' => 'EUR']);
    $this->booking = Transaction::query()->create(['bank_account_id' => $this->account->id, 'booking_date' => '2026-11-02', 'amount_minor' => -5400,
        'currency' => 'EUR', 'counterparty' => 'Muster GmbH', 'fingerprint' => Str::random(24)]);
    $this->document = Document::query()->create(['uploaded_by' => $this->owner->user_id, 'title' => 'rechnung.pdf']);
});

/** Makes a key on Mein Konto and reads it from the page it shows on once. */
function accessKey(Membership $member, string $scope): string
{
    $response = test()->actingAs($member->user)->post('/settings/account/keys', ['name' => 'Assistent', 'scope' => $scope]);
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('/settings/account?key=');
    preg_match('/dha_[A-Za-z0-9]{48}/', test()->get($location)->assertOk()->getContent(), $match);
    expect($match)->not->toBeEmpty();
    app('auth')->forgetGuards();

    return $match[0];
}

it('stays closed without a working key and stores only the hash', function (): void {
    $key = accessKey($this->owner, AccessKey::READ);
    expect(AccessKey::query()->withoutGlobalScope(OrgScope::class)->firstOrFail()->key_hash)->toBe(hash('sha256', $key));

    $this->getJson('/api/v1/transactions')->assertUnauthorized();
    $this->withToken('dha_'.Str::random(48))->getJson('/api/v1/transactions')->assertUnauthorized();
    $this->withToken($key)->getJson('/api/v1/')->assertOk()->assertJson(['scope' => 'read', 'may_link' => false]);

    AccessKey::query()->withoutGlobalScope(OrgScope::class)->update(['revoked_at' => now()]);
    $this->withToken($key)->getJson('/api/v1/transactions')->assertUnauthorized();
});

it('reads bookings and documents with "Nur lesen" but never links', function (): void {
    $key = accessKey($this->owner, AccessKey::READ);

    $this->withToken($key)->getJson('/api/v1/transactions?status=open')->assertOk()
        ->assertJsonPath('data.0.id', $this->booking->id)->assertJsonPath('data.0.amount', '-54.00')->assertJsonPath('data.0.status', 'open');
    $this->withToken($key)->getJson('/api/v1/documents?free=1')->assertOk()->assertJsonPath('data.0.id', $this->document->id);
    $this->withToken($key)->getJson("/api/v1/transactions/{$this->booking->id}")->assertOk()->assertJsonStructure(['documents', 'suggestions']);

    $this->withToken($key)->postJson("/api/v1/transactions/{$this->booking->id}/link", ['document_id' => $this->document->id])
        ->assertForbidden()->assertJson(['message' => 'Dieser Schlüssel darf nur lesen.']);
    expect(TransactionMatch::query()->count())->toBe(0);
});

it('links and unlinks with "Lesen + Verknüpfen", in the audit log with the key', function (): void {
    $key = accessKey($this->owner, AccessKey::LINK);

    $this->withToken($key)->postJson("/api/v1/transactions/{$this->booking->id}/link", ['document_id' => $this->document->id])->assertOk();
    expect(TransactionMatch::query()->where('transaction_id', $this->booking->id)->value('state'))->toBe(TransactionMatch::CONFIRMED);
    $entry = AuditLogEntry::query()->where('action', 'bank.matched')->firstOrFail();
    expect($entry->meta_json)->toMatchArray(['via' => 'api', 'document' => $this->document->id]);
    $this->withToken($key)->getJson("/api/v1/transactions/{$this->booking->id}")->assertOk()
        ->assertJsonPath('status', 'linked')->assertJsonPath('documents.0.id', $this->document->id);

    $this->withToken($key)->postJson("/api/v1/transactions/{$this->booking->id}/unlink", ['document_id' => $this->document->id])->assertOk();
    expect(TransactionMatch::query()->count())->toBe(0);
});

it('never reaches another organisation', function (): void {
    $stranger = Membership::factory()->owner()->create();
    AssistantAccess::save($stranger->organization, true);
    $key = accessKey($stranger, AccessKey::LINK);

    $this->withToken($key)->getJson('/api/v1/transactions')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($key)->getJson("/api/v1/transactions/{$this->booking->id}")->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/documents/{$this->document->id}")->assertNotFound();
    $this->withToken($key)->postJson("/api/v1/transactions/{$this->booking->id}/link", ['document_id' => $this->document->id])->assertNotFound();
    app(CurrentOrg::class)->set($this->owner);
    expect(TransactionMatch::query()->count())->toBe(0);
});
