<?php

declare(strict_types=1);

use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Matcher;
use App\Domain\Banking\MatchRejection;
use App\Domain\Banking\Transaction;
use App\Domain\Banking\TransactionMatch;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use App\Domain\Properties\PropertyValues;
use App\Events\TextRecognized;
use App\Http\Badges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
| Bank module (points 129, A267–A274): Excel import, the matcher after import and after recognition, confirm/reject,
| n:m sums, paid_at, "kein Beleg nötig", the menu badge and the permission.
*/
uses(RefreshDatabase::class)->group('db');

const INVOICE_TEXT = "Muster Handwerk GmbH · Werkstraße 5 · 12345 Musterstadt\nMax Mustermann\nRechnungsnummer: RE-2026-0042\n"
    ."Rechnungsdatum: 03.11.2026\nNettobetrag 340,00 €\nzzgl. 19 % MwSt 64,60 €\nGesamtbetrag 404,60 €\nZahlbar innerhalb 14 Tagen.\n"
    ."Kontoinhaber: Muster Handwerk GmbH\nIBAN: DE02 1203 0000 0000 2020 51";

const PAYMENTS = "Buchungstag;Empfaenger;IBAN Empfaenger;Verwendungszweck;Betrag\n"
    ."17.11.2026;Muster Handwerk GmbH;DE02120300000000202051;Rechnung RE-2026-0042;-404,60\n"
    ."18.11.2026;Cafe;;Kaffee;-3,50\n";

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->orgId = $this->admin->org_id;
    $this->actingAs($this->admin->user);
    $this->values = app(PropertyValues::class);
});

function invoice(object $test, string $title = 'rechnung.pdf', ?string $text = null): Document
{
    $doc = Document::query()->withoutGlobalScope(OrgScope::class)
        ->create(['org_id' => $test->orgId, 'uploaded_by' => $test->admin->user_id, 'title' => $title]);
    if ($text !== null) {
        $doc->forceFill(['ocr_text' => $text, 'ocr_status' => 'done'])->save();
    }

    return $doc;
}

function upload(object $test, string $content, array $fields = ['name' => 'Giro'], string $name = 'auszug.csv'): TestResponse
{
    return $test->post('/bank/import', ['file' => UploadedFile::fake()->createWithContent($name, $content), ...$fields]);
}

function importPayments(object $test, string $content = PAYMENTS): BankAccount
{
    upload($test, $content)->assertRedirect(route('bank.import.columns'));
    $test->get('/bank/import/columns')->assertSee('<option value="2" selected>IBAN Empfaenger</option>', false);
    $test->post('/bank/import/columns', ['date' => 0, 'amount' => 4, 'counterparty' => 1, 'purpose' => 3, 'iban' => 2])->assertRedirectContains('/bank/accounts/');

    return BankAccount::query()->firstOrFail();
}

function bookings(): Builder
{
    return Transaction::query()->withoutGlobalScope(OrgScope::class);
}

/** A tiny real xlsx: a title row, the header, a date cell (style 1 = date), shared and inline strings, a decimal. */
function xlsx(): string
{
    $serial = (int) (strtotime('2026-02-01 00:00:00 UTC') / 86400) + 25569;
    $strings = ['Buchungstag', 'Empfaenger', 'Verwendungszweck', 'Betrag', 'Muster GmbH', 'Rechnung 1'];
    $shared = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .implode('', array_map(static fn (string $s): string => '<si><t>'.$s.'</t></si>', $strings)).'</sst>';
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        .'<row r="1"><c r="A1" t="inlineStr"><is><t>Umsätze Girokonto</t></is></c></row>'
        .'<row r="3"><c r="A3" t="s"><v>0</v></c><c r="B3" t="s"><v>1</v></c><c r="C3" t="s"><v>2</v></c><c r="D3" t="s"><v>3</v></c></row>'
        .'<row r="4"><c r="A4" s="1"><v>'.$serial.'</v></c><c r="B4" t="s"><v>4</v></c><c r="C4" t="s"><v>5</v></c><c r="D4"><v>-12.3</v></c></row>'
        .'<row r="5"><c r="A5" s="1"><v>'.($serial + 1).'</v></c><c r="B5" t="inlineStr"><is><t>Cafe</t></is></c><c r="D5"><v>-3.5</v></c></row>'
        .'</sheetData></worksheet>';
    $styles = '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>';
    $book = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Umsätze" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';

    $path = tempnam(sys_get_temp_dir(), 'xl');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/workbook.xml', $book);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->addFromString('xl/sharedStrings.xml', $shared);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->close();
    $content = (string) file_get_contents($path);
    unlink($path);

    return $content;
}

it('imports an Excel statement, a second time adds nothing and asks nothing (F18, A267, A273)', function (): void {
    upload($this, xlsx(), ['name' => 'Giro'], 'auszug.xlsx')->assertRedirect(route('bank.import.columns'));
    $this->get('/bank/import/columns')->assertSee('Muster GmbH')->assertSee('01.02.2026')->assertSee('<option value="3" selected>Betrag</option>', false);
    $this->post('/bank/import/columns', ['date' => 0, 'amount' => 3, 'counterparty' => 1, 'purpose' => 2])
        ->assertSessionHas('status', 'Neu: 2. Schon vorhanden: 0. Nicht lesbar: 0.');
    $account = BankAccount::query()->firstOrFail();

    expect(bookings()->orderBy('booking_date')->get(['booking_date', 'amount_minor', 'counterparty'])->map(fn ($t) => [$t->booking_date->format('Y-m-d'), $t->amount_minor, $t->counterparty])->all())
        ->toBe([['2026-02-01', -1230, 'Muster GmbH'], ['2026-02-02', -350, 'Cafe']]);

    // Dropped onto the account (JSON): known columns, nothing new.
    upload($this, xlsx(), ['account' => $account->id], 'auszug.xlsx')->assertRedirect(route('bank.account', $account->id))
        ->assertSessionHas('status', 'Neu: 0. Schon vorhanden: 2. Nicht lesbar: 0.');
    $this->postJson('/bank/import', ['file' => UploadedFile::fake()->createWithContent('a.xlsx', xlsx()), 'account' => $account->id])
        ->assertOk()->assertJson(['account' => $account->id]);
    expect(bookings()->count())->toBe(2)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('finds the account by its IBAN in a dropped file, or asks the columns as JSON', function (): void {
    $account = importPayments($this);
    $account->update(['iban' => 'DE89370400440532013000']);

    $this->postJson('/bank/import', ['file' => UploadedFile::fake()->createWithContent('a.csv', "Konto;DE89 3704 0044 0532 0130 00\n\n".PAYMENTS), 'name' => 'a'])
        ->assertOk()->assertJson(['account' => $account->id]);
    $this->postJson('/bank/import', ['file' => UploadedFile::fake()->createWithContent('b.csv', PAYMENTS), 'name' => 'b'])
        ->assertOk()->assertJson(['columns' => route('bank.import.columns')]);
    $this->postJson('/bank/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', 'nur Text'), 'name' => 'c'])->assertStatus(422);

    expect(BankAccount::query()->count())->toBe(1);
});

it('suggests the matching document after an import, confirm sets paid_at, unlink removes it (A268, A272)', function (): void {
    $doc = invoice($this);
    $this->values->set($doc, 'invoice_amount', '404,60');
    $this->values->set($doc, 'iban', 'DE02120300000000202051');
    $this->values->set($doc, 'invoice_number', 'RE-2026-0042');
    $other = invoice($this, 'kaffee.jpg');

    importPayments($this);
    $payment = bookings()->where('amount_minor', -40460)->firstOrFail();

    $match = TransactionMatch::query()->firstOrFail();
    expect(TransactionMatch::query()->count())->toBe(1)
        ->and($match->transaction_id)->toBe($payment->id)
        ->and($match->document_id)->toBe($doc->id)
        ->and($match->state)->toBe('suggested')
        ->and($match->score)->toBeGreaterThanOrEqual(90)
        ->and($payment->status())->toBe('suggested')
        ->and($this->values->get($doc, 'paid_at'))->toBeNull();

    $this->get('/bank/transactions/'.$payment->id)->assertOk()->assertSee('rechnung.pdf')->assertSee('Bestätigen')->assertSee('kaffee.jpg');

    $this->postJson('/bank/transactions/'.$payment->id.'/confirm', ['document_id' => $doc->id])->assertOk()->assertJson(['message' => 'Zuordnung bestätigt.']);
    expect($match->fresh()->state)->toBe('confirmed')
        ->and($payment->fresh()->status())->toBe('linked')
        ->and($this->values->get($doc, 'paid_at')?->value_date?->format('Y-m-d'))->toBe('2026-11-17');

    $this->post('/bank/transactions/'.$payment->id.'/unmatch', ['document_id' => $doc->id])->assertRedirect(route('bank.show', $payment->id));
    expect($this->values->get($doc, 'paid_at'))->toBeNull()
        ->and($other->matches()->count())->toBe(0);
});

it('keeps a paid_at that was there before and never suggests a rejected pair again', function (): void {
    $doc = invoice($this);
    $this->values->set($doc, 'invoice_amount', '404,60');
    $this->values->set($doc, 'iban', 'DE02120300000000202051');
    $this->values->set($doc, 'invoice_number', 'RE-2026-0042');
    $this->values->set($doc, 'paid_at', '15.11.2026');

    importPayments($this);
    $payment = bookings()->where('amount_minor', -40460)->firstOrFail();

    $this->post('/bank/transactions/'.$payment->id.'/reject', ['document_id' => $doc->id])->assertRedirect();
    expect(TransactionMatch::query()->count())->toBe(0)->and(MatchRejection::query()->count())->toBe(1);

    expect(app(Matcher::class)->afterImport($this->orgId, [$payment->id]))->toBe(0);
    $this->get('/bank/transactions/'.$payment->id)->assertDontSee('rechnung.pdf');

    // Linked by hand after all: the rejection goes, the old paid_at stays – also after unlinking.
    $this->post('/bank/transactions/'.$payment->id.'/match', ['document_id' => $doc->id])->assertRedirect();
    expect(MatchRejection::query()->count())->toBe(0)->and($this->values->get($doc, 'paid_at')?->value_date?->format('Y-m-d'))->toBe('2026-11-15');
    $this->post('/bank/transactions/'.$payment->id.'/unmatch', ['document_id' => $doc->id]);
    expect($this->values->get($doc, 'paid_at')?->value_date?->format('Y-m-d'))->toBe('2026-11-15');
});

it('suggests after a recognition: the read invoice finds its open booking (A268)', function (): void {
    importPayments($this);
    $payment = bookings()->where('amount_minor', -40460)->firstOrFail();
    expect(TransactionMatch::query()->count())->toBe(0);

    app(CurrentOrg::class)->clear();
    $doc = invoice($this, 'rechnung.pdf', INVOICE_TEXT);
    TextRecognized::dispatch($doc->id);

    $match = TransactionMatch::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();
    expect($match->transaction_id)->toBe($payment->id)
        ->and($match->document_id)->toBe($doc->id)
        ->and($match->state)->toBe('suggested')
        ->and($match->score)->toBeGreaterThanOrEqual(80);
});

it('links several documents by drag and shows the sums n:m (A269, A271)', function (): void {
    $account = importPayments($this);
    $payment = bookings()->where('amount_minor', -40460)->firstOrFail();
    $coffee = bookings()->where('amount_minor', -350)->firstOrFail();
    $a = invoice($this, 'teil-a.pdf');
    $b = invoice($this, 'teil-b.pdf');
    $this->values->set($a, 'invoice_amount', '400,00');
    $this->values->set($b, 'invoice_amount', '4,60');

    $this->postJson('/bank/transactions/'.$payment->id.'/link', ['ids' => [$a->id, $b->id]])->assertOk()->assertJson(['message' => '2 Dokumente zugeordnet.']);
    $this->get('/bank/transactions/'.$payment->id)->assertSee('teil-a.pdf')->assertSee('teil-b.pdf')->assertSee('passt');

    // A document paid by two bookings: the second shows a difference and what the document got in total.
    $this->postJson('/bank/transactions/'.$coffee->id.'/link', ['ids' => [$a->id]])->assertOk();
    $this->get('/bank/transactions/'.$coffee->id)->assertSee('-396,50 EUR')->assertSee('bezahlt 408,10 EUR über 2 Buchungen');

    expect(TransactionMatch::query()->where('document_id', $a->id)->count())->toBe(2);
    $this->get('/bank/accounts/'.$account->id.'?status=linked')->assertSee('Muster Handwerk GmbH')->assertSee('Cafe');
});

it('marks "kein Beleg nötig" with a reason, filters by status and counts the open ones in the menu (A270, A274)', function (): void {
    $account = importPayments($this);
    $coffee = bookings()->where('amount_minor', -350)->firstOrFail();

    $this->getJson('/badges')->assertJson([Badges::BANK => 2]);
    $this->get('/bank')->assertSee('2 offen', false);

    $this->post('/bank/transactions/'.$coffee->id.'/no-receipt', ['reason' => ''])->assertSessionHasErrors('reason');
    $this->postJson('/bank/transactions/'.$coffee->id.'/no-receipt', ['reason' => 'Bewirtung bar belegt'])->assertOk();
    expect($coffee->fresh()->status())->toBe('none')->and($coffee->fresh()->no_receipt_reason)->toBe('Bewirtung bar belegt');

    $this->getJson('/badges')->assertJson([Badges::BANK => 1]);
    $this->get('/bank/accounts/'.$account->id.'?status=none')->assertSee('Cafe')->assertDontSee('Muster Handwerk GmbH');
    $this->get('/bank/accounts/'.$account->id.'?status=open')->assertSee('Muster Handwerk GmbH')->assertDontSee('Kaffee');

    $this->postJson('/bank/transactions/'.$coffee->id.'/no-receipt', ['undo' => 1])->assertOk();
    expect($coffee->fresh()->status())->toBe('open');
});

it('needs banking.import for every bank route and the badge', function (): void {
    importPayments($this);
    $payment = bookings()->firstOrFail();
    $doc = invoice($this);

    $advisor = Membership::factory()->accountant()->create(['org_id' => $this->orgId]);
    $this->actingAs($advisor->user);
    $this->get('/bank/accounts/'.$payment->bank_account_id)->assertForbidden();
    $this->get('/bank/receipts')->assertForbidden();
    $this->postJson('/bank/transactions/'.$payment->id.'/link', ['ids' => [$doc->id]])->assertForbidden();
    $this->postJson('/bank/transactions/'.$payment->id.'/no-receipt', ['reason' => 'x'])->assertForbidden();
    $this->getJson('/badges')->assertJsonMissing([Badges::BANK => 2]);

    $clerk = Membership::factory()->withRights(RoleRight::Banking)->create(['org_id' => $this->orgId]);
    $this->actingAs($clerk->user);
    // Someone else's document can't be linked without "Alle Dokumente sehen".
    $this->postJson('/bank/transactions/'.$payment->id.'/link', ['ids' => [$doc->id]])->assertStatus(422);
    expect(TransactionMatch::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(0);
});
