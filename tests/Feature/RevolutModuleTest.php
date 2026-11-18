<?php

declare(strict_types=1);

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Modules\InstalledModules;
use App\Modules\Manifest;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleLoader;
use DocHamsterModules\Revolut\Mapper;
use DocHamsterModules\Revolut\Signer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Point 37, 38: the Revolut module (modules/revolut) with synthetic Revolut answers – no network. Signing as Revolut
| describes it, accounts, Probelauf without storing, the import (a CSV booking with the same Revolut id is not doubled,
| a pending one is completed), a receipt from an expense linked to its booking, and a payment draft.
*/
uses(RefreshDatabase::class)->group('db');

const REV_ACCOUNT = '7e4c1d30-1111-4a2b-9c3d-000000000001';

beforeEach(function (): void {
    Storage::fake('local');
    $this->root = sys_get_temp_dir().'/dh-rev-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->root);
    $this->app->instance(InstalledModules::class, new InstalledModules($this->root));
    $this->owner = Membership::factory()->owner()->create();
    app(CurrentOrg::class)->set($this->owner);

    $zipFile = $this->root.'/module.zip';
    $zip = new ZipArchive;
    $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (File::allFiles(base_path('modules/revolut'), true) as $file) {
        $zip->addFile($file->getPathname(), str_replace('\\', '/', $file->getRelativePathname()));
    }
    $zip->close();
    $manifest = Manifest::from(json_decode((string) file_get_contents(base_path('modules/revolut/dochamster-module.json')), true));
    app(ModuleInstaller::class)->install($manifest, 'batman/doc-hamster#module-revolut', $manifest->rights, $zipFile);
    app(ModuleLoader::class)->boot();
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

/** Synthetic Revolut answers. */
function revolutFake(array $transactions): void
{
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    Http::fake([
        'b2b.revolut.com/api/1.0/accounts/*/bank-details' => Http::response([['iban' => 'DE00 0000 0000 0000 0000 00', 'bic' => 'REVODEXX', 'beneficiary' => 'Muster GmbH']]),
        'b2b.revolut.com/api/1.0/accounts' => Http::response([['id' => REV_ACCOUNT, 'name' => 'Main', 'balance' => 100.5, 'currency' => 'EUR', 'state' => 'active', 'public' => false,
            'created_at' => '2026-01-01T00:00:00Z', 'updated_at' => '2026-01-01T00:00:00Z', 'account_type' => 'current']]),
        'b2b.revolut.com/api/1.0/transactions*' => Http::response($transactions),
        'b2b.revolut.com/api/1.0/expenses/*/receipts/*/content' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        'b2b.revolut.com/api/1.0/expenses*' => Http::response([['id' => 'e0000000-0000-4000-8000-000000000001', 'state' => 'approved', 'transaction_type' => 'card_payment',
            'expense_date' => '2026-11-10T09:00:00Z', 'merchant' => 'Muster Café', 'transaction_id' => 't0000000-0000-4000-8000-000000000001', 'labels' => (object) [], 'splits' => [],
            'spent_amount' => ['amount' => 8.7, 'currency' => 'EUR'], 'receipt_ids' => ['r0000000-0000-4000-8000-000000000001']]]),
        'b2b.revolut.com/api/1.0/counterparties*' => Http::response([]),
        'b2b.revolut.com/api/1.0/counterparty' => Http::response(['id' => 'c0000000-0000-4000-8000-000000000001', 'name' => 'Muster GmbH', 'state' => 'created',
            'created_at' => '2026-11-10T09:00:00Z', 'updated_at' => '2026-11-10T09:00:00Z', 'accounts' => [['id' => 'a0000000-0000-4000-8000-000000000001', 'currency' => 'EUR', 'type' => 'external', 'iban' => 'DE89370400440532013000']]]),
        'b2b.revolut.com/api/1.0/payment-drafts' => Http::response(['id' => 'd0000000-0000-4000-8000-000000000001'], 201),
    ]);
}

function revolutTransaction(string $id, string $state, float $amount, string $merchant): array
{
    return ['id' => $id, 'type' => 'card_payment', 'state' => $state, 'created_at' => '2026-11-10T09:00:00Z', 'updated_at' => '2026-11-10T09:05:00Z',
        'completed_at' => $state === 'completed' ? '2026-11-10T09:05:00Z' : null, 'reference' => null,
        'merchant' => ['name' => $merchant, 'city' => 'Musterstadt', 'category_code' => '5814', 'country' => 'DE'],
        'legs' => [['leg_id' => 'l-'.$id, 'account_id' => REV_ACCOUNT, 'amount' => $amount, 'currency' => 'EUR', 'description' => $merchant, 'balance' => 91.8]],
        'card' => ['card_number' => '459678******1234']];
}

it('signs like Revolut describes and hands out the public key as PEM', function (): void {
    $pair = Signer::generate();
    $message = Signer::message('1783616701644', 'get', '/api/1.0/team-members', '', '');
    expect($message)->toBe('1783616701644GET/api/1.0/team-members')
        ->and(sodium_crypto_sign_verify_detached(base64_decode(Signer::sign($message, $pair['secret'])), $message, sodium_crypto_sign_publickey_from_secretkey($pair['secret'])))->toBeTrue()
        ->and($pair['pem'])->toStartWith("-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEA");
});

it('maps a card payment to a booking line with the Revolut id', function (): void {
    $lines = Mapper::lines(revolutTransaction('t1', 'completed', -8.7, 'Muster Café'));
    expect($lines[REV_ACCOUNT])->toMatchArray(['amount_minor' => -870, 'currency' => 'EUR', 'counterparty' => 'Muster Café', 'booking_id' => 't1',
        'kind' => 'CARD_PAYMENT', 'bank_state' => 'completed', 'card_last4' => '1234', 'mcc' => '5814']);
});

it('connects, shows a Probelauf without storing, imports without doubling and brings the receipt along', function (): void {
    $tx = 't0000000-0000-4000-8000-000000000001';
    revolutFake([revolutTransaction($tx, 'completed', -8.7, 'Muster Café'), revolutTransaction('t0000000-0000-4000-8000-000000000002', 'pending', -20, 'Muster Tankstelle')]);
    // The same card payment came by CSV before (same Revolut id).
    $csv = BankAccount::query()->create(['name' => 'Revolut EUR (CSV)', 'currency' => 'EUR']);
    Transaction::query()->create(['bank_account_id' => $csv->id, 'booking_date' => '2026-11-10', 'amount_minor' => -870, 'currency' => 'EUR', 'counterparty' => 'Muster Café',
        'fingerprint' => 'csv-1', 'booking_id' => $tx]);

    $this->actingAs($this->owner->user);
    $this->post('/m/revolut/settings/keygen')->assertRedirect();
    $this->post('/m/revolut/settings/key', ['api_key' => str_repeat('k', 40)])->assertRedirect()->assertSessionHas('status');
    Http::assertSent(static fn (HttpRequest $r): bool => $r->hasHeader('Revolut-Api-Signature') && $r->hasHeader('Revolut-Api-Timestamp') && $r->header('Authorization')[0] === 'Bearer '.str_repeat('k', 40));

    $this->postJson('/m/revolut/settings/map', ['mapping' => [REV_ACCOUNT => $csv->id]])->assertOk();
    $this->post('/m/revolut/settings/probe')->assertRedirect();
    $probe = session('m-revolut.probe');
    expect($probe[0]['new'])->toBe(1)->and($probe[0]['known'])->toBe(1)
        ->and(Transaction::query()->count())->toBe(1);

    $this->post('/m/revolut/settings/accept')->assertRedirect()->assertSessionHas('status');
    expect(Transaction::query()->where('bank_account_id', $csv->id)->count())->toBe(2)
        ->and($csv->refresh()->source_key)->toBe('revolut:'.REV_ACCOUNT);
    $booking = Transaction::query()->where('booking_id', $tx)->firstOrFail();
    expect(TransactionMatch::query()->where('transaction_id', $booking->id)->where('state', TransactionMatch::CONFIRMED)->count())->toBe(1);

    // Fetching again: nothing new, the receipt is not brought twice.
    $this->post('/m/revolut/settings/sync')->assertRedirect();
    expect(Transaction::query()->count())->toBe(2)
        ->and(TransactionMatch::query()->where('transaction_id', $booking->id)->count())->toBe(1);
});

it('prepares a payment draft that only a person sends in Revolut (point 38)', function (): void {
    revolutFake([]);
    $this->actingAs($this->owner->user);
    $this->post('/m/revolut/settings/keygen');
    $this->post('/m/revolut/settings/key', ['api_key' => str_repeat('k', 40)]);
    $this->post('/m/revolut/settings/drafts', ['on' => '1']);
    $document = Document::query()->create(['uploaded_by' => $this->owner->user_id, 'title' => 'Rechnung R-1']);

    $this->get("/m/revolut/documents/{$document->id}/draft")->assertOk()->assertSee('Zahlungs-Entwurf');
    $this->post("/m/revolut/documents/{$document->id}/draft", ['account_id' => REV_ACCOUNT, 'name' => 'Muster GmbH', 'iban' => 'DE89 3704 0044 0532 0130 00',
        'amount' => '119,00', 'currency' => 'EUR', 'reference' => 'R-1'])->assertSessionHas('status');
    Http::assertSent(static fn (HttpRequest $r): bool => str_ends_with($r->url(), '/payment-drafts') && $r['payments'][0]['amount'] == 119.0
        && $r['payments'][0]['receiver']['counterparty_id'] === 'c0000000-0000-4000-8000-000000000001' && $r['payments'][0]['reference'] === 'R-1');
});
