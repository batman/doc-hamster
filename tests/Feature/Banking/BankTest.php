<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogEntry;
use App\Domain\Auth\User;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| MVP-CSV: bank statements from any bank, receipts linked by hand.
*/
uses(RefreshDatabase::class)->group('db');

const STATEMENT = "Kontonummer;DE00 0000 0000 0000 0000 00\n\nBuchungstag;Empfaenger;Verwendungszweck;Betrag\n01.02.2026;Muster GmbH;Rechnung 1;-12,30\n02.02.2026;Cafe;Kaffee;-3,50\n02.02.2026;Cafe;Kaffee;-3,50\nSumme;;;-19,30\n";

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->orgId = $this->admin->org_id;
});

function statementFile(string $content = STATEMENT): UploadedFile
{
    return UploadedFile::fake()->createWithContent('auszug.csv', $content);
}

function importStatement(object $test, array $upload = ['name' => 'Girokonto'], string $content = STATEMENT): void
{
    $test->post('/bank/import', ['file' => statementFile($content), ...$upload])->assertRedirect(route('bank.import.columns'));
    $test->post('/bank/import/columns', ['date' => 0, 'amount' => 3, 'counterparty' => 1, 'purpose' => 2])->assertRedirect(route('bank.index'));
}

function receipt(string $orgId, ?string $uploadedBy = null, string $title = 'bon.jpg'): Document
{
    return Document::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $orgId, 'uploaded_by' => $uploadedBy, 'title' => $title]);
}

function transactions(): Builder
{
    return Transaction::query()->withoutGlobalScope(OrgScope::class);
}

it('shows the guessed columns and the first rows, then imports and skips unreadable rows', function (): void {
    $this->actingAs($this->admin->user)->post('/bank/import', ['file' => statementFile(), 'name' => 'Girokonto'])
        ->assertRedirect(route('bank.import.columns'));

    $this->get('/bank/import/columns')->assertOk()->assertSee('Girokonto')->assertSee('Muster GmbH')
        ->assertSee('<option value="3" selected>Betrag</option>', false);

    $this->post('/bank/import/columns', ['date' => 0, 'amount' => 3, 'counterparty' => 1, 'purpose' => 2])
        ->assertRedirect(route('bank.index'))
        ->assertSessionHas('status', 'Neu: 3. Schon vorhanden: 0. Nicht lesbar: 1.');

    expect(transactions()->orderBy('amount_minor')->pluck('amount_minor')->all())->toBe([-1230, -350, -350])
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('adds nothing on a re-import and only what is new on an overlapping one', function (): void {
    $this->actingAs($this->admin->user);
    importStatement($this);
    $account = BankAccount::query()->firstOrFail();

    importStatement($this, ['account' => $account->id]);
    expect(transactions()->count())->toBe(3);

    importStatement($this, ['account' => $account->id], STATEMENT."03.02.2026;Neu;Neu;-1,00\n");
    expect(transactions()->count())->toBe(4)
        ->and(BankAccount::query()->count())->toBe(1);
});

it('remembers the columns per account, and accounts are not limited', function (): void {
    $moved = "Betrag;Wann;Wer;Wofuer\n-5,00;01.02.2026;Laden;Sachen\n";

    $this->actingAs($this->admin->user)->post('/bank/import', ['file' => statementFile($moved), 'name' => 'Karte']);
    $this->post('/bank/import/columns', ['date' => 1, 'amount' => 0, 'counterparty' => 2, 'purpose' => 3])->assertRedirect();
    $card = BankAccount::query()->firstOrFail();

    $this->post('/bank/import', ['file' => statementFile($moved), 'account' => $card->id]);
    $this->get('/bank/import/columns')->assertSee('<option value="1" selected>Wann</option>', false);

    $this->post('/bank/import', ['file' => statementFile($moved), 'name' => 'Zweites Konto']);
    $this->post('/bank/import/columns', ['date' => 1, 'amount' => 0])->assertRedirect();

    expect(BankAccount::query()->count())->toBe(2);
});

it('keeps the upload if no row is readable and creates no account', function (): void {
    $this->actingAs($this->admin->user)->post('/bank/import', ['file' => statementFile(), 'name' => 'Falsch']);

    $this->from('/bank/import/columns')->post('/bank/import/columns', ['date' => 1, 'amount' => 2])
        ->assertRedirect('/bank/import/columns')->assertSessionHasErrors('date');

    expect(BankAccount::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('refuses files that are no statement and needs an account name', function (): void {
    $this->actingAs($this->admin->user);

    $this->post('/bank/import', ['file' => statementFile("PK\x03\x04\0\0binary;x\n1;2\n"), 'name' => 'Konto'])->assertSessionHasErrors('file');
    $this->post('/bank/import', ['file' => statementFile('nur Text'), 'name' => 'Konto'])->assertSessionHasErrors('file');
    $this->post('/bank/import', ['file' => statementFile()])->assertSessionHasErrors('name');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('does not import from a session without an upload', function (): void {
    $this->actingAs($this->admin->user)->post('/bank/import/columns', ['date' => 0, 'amount' => 1])
        ->assertRedirect(route('bank.import'))->assertSessionHasErrors('file');
});

it('names the count before deleting an account, keeps the receipts', function (): void {
    $this->actingAs($this->admin->user);
    importStatement($this);
    $account = BankAccount::query()->firstOrFail();
    $transaction = transactions()->firstOrFail();
    $document = receipt($this->orgId, $this->admin->user_id);
    $this->post('/bank/transactions/'.$transaction->id.'/match', ['document_id' => $document->id]);

    $this->post('/bank/accounts/'.$account->id.'/delete')->assertRedirect(route('bank.import'))->assertSessionHas('confirm_delete');
    expect(transactions()->count())->toBe(3);

    $this->post('/bank/accounts/'.$account->id.'/delete', ['confirmed' => 1])->assertRedirect(route('bank.import'));

    expect(transactions()->count())->toBe(0)
        ->and(TransactionMatch::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(0)
        ->and($document->fresh())->not->toBeNull();
});

it('links a receipt, moves it between the views and can undo it', function (): void {
    $this->actingAs($this->admin->user);
    importStatement($this);
    $transaction = transactions()->where('amount_minor', -1230)->firstOrFail();
    $document = receipt($this->orgId, $this->admin->user_id);

    $this->get('/bank')->assertSee('Muster GmbH');
    $this->get('/bank?view=receipts')->assertSee('bon.jpg');
    $this->get('/bank/transactions/'.$transaction->id)->assertOk()->assertSee('bon.jpg');

    $this->post('/bank/transactions/'.$transaction->id.'/match', ['document_id' => $document->id])->assertRedirect(route('bank.index'));
    $this->post('/bank/transactions/'.$transaction->id.'/match', ['document_id' => $document->id])->assertRedirect();

    expect(TransactionMatch::query()->count())->toBe(1);
    $this->get('/bank')->assertDontSee('Muster GmbH');
    $this->get('/bank?view=matched')->assertSee('Muster GmbH')->assertSee('1 Beleg');
    $this->get('/bank?view=receipts')->assertDontSee('bon.jpg');

    $this->post('/bank/transactions/'.$transaction->id.'/unmatch', ['document_id' => $document->id])->assertRedirect();

    expect(TransactionMatch::query()->count())->toBe(0);
    expect(AuditLogEntry::query()->pluck('action')->all())->toContain('bank.imported', 'bank.matched', 'bank.unmatched');
});

it('keeps other organisations out', function (): void {
    $other = Membership::factory()->owner()->create();

    $this->actingAs($other->user);
    importStatement($this);
    $foreignTransaction = transactions()->firstOrFail();
    $foreignAccount = BankAccount::query()->firstOrFail();
    $foreignReceipt = receipt($other->org_id, $other->user_id);

    $this->actingAs($this->admin->user)->get('/bank')->assertDontSee('Muster GmbH');
    $this->get('/bank/transactions/'.$foreignTransaction->id)->assertNotFound();
    $this->post('/bank/accounts/'.$foreignAccount->id.'/delete', ['confirmed' => 1])->assertNotFound();
    $this->post('/bank/import', ['file' => statementFile(), 'account' => $foreignAccount->id])->assertSessionHasErrors('account');

    importStatement($this);
    $this->post('/bank/transactions/'.transactions()->where('org_id', $this->orgId)->firstOrFail()->id.'/match', ['document_id' => $foreignReceipt->id])
        ->assertSessionHasErrors('document_id');

    expect(TransactionMatch::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(0);
});

it('needs the checkbox, and without "Alle Belege sehen" only own receipts can be linked', function (): void {
    $advisor = Membership::factory()->accountant()->create(['org_id' => $this->orgId]);
    $this->actingAs($advisor->user)->get('/bank')->assertForbidden();
    $this->post('/bank/import', ['file' => statementFile(), 'name' => 'Konto'])->assertForbidden();

    $this->actingAs($this->admin->user);
    importStatement($this);
    $transaction = transactions()->firstOrFail();
    $foreign = receipt($this->orgId, $this->admin->user_id, 'chef.jpg');
    $this->post('/bank/transactions/'.$transaction->id.'/match', ['document_id' => $foreign->id]);

    $clerk = Membership::factory()->withRights(RoleRight::Banking)->create(['org_id' => $this->orgId]);
    $own = receipt($this->orgId, $clerk->user_id, 'meins.jpg');

    $second = transactions()->whereKeyNot($transaction->id)->firstOrFail();
    $this->actingAs($clerk->user)->get('/bank/transactions/'.$second->id)->assertSee('meins.jpg')->assertDontSee('chef.jpg');
    $this->get('/bank?view=receipts')->assertSee('meins.jpg');
    $this->get('/bank/transactions/'.$transaction->id)->assertDontSee('chef.jpg')->assertSee('1 Beleg, den Sie nicht sehen');

    $this->post('/bank/transactions/'.$second->id.'/match', ['document_id' => $foreign->id])->assertSessionHasErrors('document_id');
    $this->post('/bank/transactions/'.$transaction->id.'/unmatch', ['document_id' => $foreign->id])->assertNotFound();
    $this->post('/bank/transactions/'.$second->id.'/match', ['document_id' => $own->id])->assertRedirect(route('bank.index'));

    expect(TransactionMatch::query()->count())->toBe(2);
});

it('lists the booking in the index of the export, without the purpose', function (): void {
    $user = User::factory()->create(['password_hash' => 'ein-langes-test-passwort']);
    $member = Membership::factory()->owner()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post('/documents', ['file' => UploadedFile::fake()->image('bon.jpg')]);
    importStatement($this);
    $document = Document::query()->firstOrFail();
    $this->post('/bank/transactions/'.transactions()->where('amount_minor', -1230)->firstOrFail()->id.'/match', ['document_id' => $document->id]);

    $today = today()->format('Y-m-d');
    $path = $this->post('/export', ['from' => $today, 'to' => $today, 'password' => 'ein-langes-test-passwort'])
        ->assertOk()->baseResponse->getFile()->getPathname();

    $zip = new ZipArchive;
    $zip->open($path);
    $index = (string) $zip->getFromName('index.csv');
    $zip->close();
    unlink($path);

    expect($index)->toContain('01.02.2026 -12,30 EUR Muster GmbH')
        ->and($index)->not->toContain('Rechnung 1')
        ->and($member->org_id)->not->toBe($this->orgId);
});
