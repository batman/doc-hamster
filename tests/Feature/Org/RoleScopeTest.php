<?php

declare(strict_types=1);

use App\Domain\Auth\User;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\Transaction;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Folder;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgRole;
use App\Domain\Org\OrgScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
| F39: a folder-restricted role sees only its branches and the path to them – nowhere another folder's name – and files
| and manages folders only inside them. F40: "Bank ansehen" opens the Bank module read-only. F41: an account restriction
| hides documents without a booking.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->owner = Membership::factory()->owner()->create();
    $this->orgId = $this->owner->org_id;
    app(CurrentOrg::class)->set($this->owner);
    $this->giro = BankAccount::query()->create(['name' => 'Giro', 'currency' => 'EUR']);
    $this->cash = BankAccount::query()->create(['name' => 'Kasse', 'currency' => 'EUR', 'kind' => 'cash']);
    $this->top = Folder::query()->create(['name' => 'Firma']);
    $this->sub = Folder::query()->create(['name' => 'Projekt Nord', 'parent_id' => $this->top->id]);
    $this->deep = Folder::query()->create(['name' => 'Reisen', 'parent_id' => $this->sub->id]);
    $this->sibling = Folder::query()->create(['name' => 'Geheimprojekt', 'parent_id' => $this->top->id]);
    $this->other = Folder::query()->create(['name' => 'Privat']);
    $this->role = OrgRole::query()->create(['name' => 'Projekt', 'rights' => ['view_all', 'edit', 'upload']]);
    app(CurrentOrg::class)->clear();
    $this->person = Membership::factory()->create(['org_id' => $this->orgId, 'org_role_id' => $this->role->id, 'user_id' => User::factory()->create()->id]);
});

function scopedDoc(string $title, ?string $folder): Document
{
    return Document::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => test()->orgId, 'uploaded_by' => test()->owner->user_id, 'title' => $title, 'folder_id' => $folder, 'doc_date' => '2026-03-01']);
}

function scopedBooking(BankAccount $account, ?Document $document = null): Transaction
{
    $booking = Transaction::query()->withoutGlobalScopes()->create(['org_id' => test()->orgId, 'bank_account_id' => $account->id, 'booking_date' => '2026-03-01', 'amount_minor' => -100,
        'currency' => 'EUR', 'counterparty' => 'Muster', 'purpose' => 'x', 'fingerprint' => Str::random(64)]);
    if ($document !== null) {
        DB::table('matches')->insert(['id' => Str::lower((string) Str::ulid()), 'org_id' => test()->orgId, 'transaction_id' => $booking->id, 'document_id' => $document->id, 'state' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $booking;
}

it('shows a folder-restricted role only its branch and the path to it, no other folder names (F39)', function (): void {
    $this->role->forceFill(['folder_ids' => [$this->sub->id]])->save();
    $inside = scopedDoc('Hotel', $this->deep->id);
    $this->actingAs($this->person->user);

    $this->get('/documents')->assertOk()->assertSee('Firma')->assertDontSee('Geheimprojekt')->assertDontSee('Privat')->assertDontSee(__('folders.default'));
    $this->get('/documents?folder='.$this->top->id)->assertOk()->assertSee('Projekt Nord')->assertDontSee('Geheimprojekt');
    $this->get('/documents?folder='.$this->sibling->id)->assertOk()->assertDontSee('Geheimprojekt');
    // The document detail with its folder picker and "Verschieben …" menu.
    $this->get('/documents/'.$inside->id)->assertOk()->assertSee('Reisen')->assertDontSee('Geheimprojekt')->assertDontSee('Privat');
    $this->get('/documents?q=Hotel')->assertOk()->assertSee('Hotel')->assertDontSee('Geheimprojekt');

    // Unrestricted again: every folder.
    $this->role->forceFill(['folder_ids' => null])->save();
    $this->get('/documents')->assertOk()->assertSee('Privat');
});

it('lets a folder-restricted role file and manage folders only inside its branch (F39)', function (): void {
    $this->role->forceFill(['folder_ids' => [$this->sub->id]])->save();
    $doc = scopedDoc('Hotel', $this->sub->id);
    $this->actingAs($this->person->user);

    // Into the path above, a sibling, another top folder or back into a Posteingang: refused.
    foreach ([$this->top->id, $this->sibling->id, $this->other->id, null] as $target) {
        $this->postJson('/documents/bulk/move', ['ids' => [$doc->id], 'folder' => $target])->assertUnprocessable();
    }
    $this->postJson('/documents/bulk/move', ['ids' => [$doc->id], 'folder' => $this->deep->id])->assertOk();
    expect($doc->fresh()?->folder_id)->toBe($this->deep->id);

    // The ticked folder and the path stay as the Admin set them; below them the role is free.
    $this->postJson('/folders/'.$this->sub->id, ['name' => 'Umbenannt', 'only_name' => '1'])->assertForbidden();
    $this->postJson('/folders/'.$this->top->id.'/delete')->assertForbidden();
    $this->postJson('/folders/'.$this->sibling->id, ['name' => 'X', 'only_name' => '1'])->assertNotFound();
    $this->post('/folders', ['name' => 'Neu oben'])->assertSessionHasErrors('parent_id');
    $this->post('/folders', ['name' => 'Neu neben', 'parent_id' => $this->top->id])->assertSessionHasErrors('parent_id');
    $this->post('/folders', ['name' => 'Belege 2026', 'parent_id' => $this->sub->id])->assertSessionHasNoErrors();
    $this->postJson('/folders/'.$this->deep->id, ['name' => 'Reisekosten', 'only_name' => '1'])->assertOk();
    $this->postJson('/folders/'.$this->deep->id.'/move', ['parent_id' => $this->top->id])->assertUnprocessable();

    expect(Folder::query()->withoutGlobalScopes()->where('name', 'Belege 2026')->value('parent_id'))->toBe($this->sub->id)
        ->and($this->deep->fresh()?->name)->toBe('Reisekosten')
        ->and($this->sub->fresh()?->name)->toBe('Projekt Nord');
});

it('opens the Bank read-only with "Bank ansehen"; "Bank" includes it (F40)', function (): void {
    $this->role->forceFill(['rights' => ['view_all', 'bank_view']])->save();
    $doc = scopedDoc('Rechnung', $this->top->id);
    $booking = scopedBooking($this->giro, $doc);
    $this->actingAs($this->person->user);

    $this->get('/bank')->assertOk()->assertSee('Giro')->assertDontSee(__('bank.add_account'))->assertDontSee(__('bank.manage'));
    $this->get('/bank/accounts/'.$this->giro->id)->assertOk();
    $this->get('/bank/transactions/'.$booking->id)->assertOk()->assertSee('Rechnung')->assertDontSee(__('bank.suggestions'))->assertDontSee(__('bank.unlink'));
    $this->get('/bank/receipts')->assertOk();

    $this->get('/bank/import')->assertForbidden();
    $this->get('/bank/transactions/'.$booking->id.'/suggestions')->assertForbidden();
    $this->postJson('/bank/transactions/'.$booking->id.'/note', ['field' => 'note', 'value' => 'x'])->assertForbidden();
    $this->postJson('/bank/transactions/'.$booking->id.'/unmatch', ['document_id' => $doc->id])->assertForbidden();
    $this->postJson('/bank/transactions/'.$booking->id.'/link', ['ids' => [$doc->id]])->assertForbidden();
    $this->postJson('/bank/accounts', ['name' => 'Neu', 'kind' => 'cash'])->assertForbidden();
    $this->postJson('/bank/accounts/'.$this->giro->id.'/rename', ['name' => 'Umbenannt'])->assertForbidden();
    expect(DB::table('matches')->count())->toBe(1);

    $this->role->forceFill(['rights' => ['banking']])->save();
    $this->get('/bank')->assertOk()->assertSee(__('bank.add_account'));

    $this->role->forceFill(['rights' => ['view_all']])->save();
    $this->get('/bank')->assertForbidden();
});

it('hides documents without a booking from a role restricted to accounts (F41)', function (): void {
    $giroDoc = scopedDoc('Giro-Beleg', $this->top->id);
    scopedBooking($this->giro, $giroDoc);
    $cashDoc = scopedDoc('Kassen-Beleg', $this->top->id);
    scopedBooking($this->cash, $cashDoc);
    scopedDoc('Ohne Buchung', $this->top->id);
    $both = scopedDoc('Beide Konten', $this->top->id);
    scopedBooking($this->giro, $both);
    scopedBooking($this->cash, $both);
    $titles = function (): array {
        $org = app(CurrentOrg::class);
        $org->set($this->person->fresh() ?? $this->person);
        $org->restrictTo($this->role->fresh()?->restriction());

        return Document::query()->visibleTo($this->person->user_id, true)->orderBy('title')->pluck('title')->all();
    };

    $this->role->forceFill(['account_ids' => [$this->giro->id]])->save();
    expect($titles())->toBe(['Beide Konten', 'Giro-Beleg']);

    $this->role->forceFill(['account_ids' => null])->save();
    expect($titles())->toBe(['Beide Konten', 'Giro-Beleg', 'Kassen-Beleg', 'Ohne Buchung']);
});

it('puts only what the role may see into a folder or selection ZIP, keeping the tree (point 237)', function (): void {
    $this->role->forceFill(['folder_ids' => [$this->sub->id]])->save();
    $files = [];
    foreach ([['Hotel', $this->deep->id], ['Hotel', $this->sub->id], ['Geheim', $this->sibling->id]] as [$title, $folder]) {
        $doc = scopedDoc($title, $folder);
        $path = 'documents/'.$doc->id.'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 '.$title);
        DocumentFile::query()->withoutGlobalScopes()->create(['org_id' => $this->orgId, 'document_id' => $doc->id, 'path' => $path, 'mime' => 'application/pdf', 'size' => 12, 'sha256' => str_repeat('0', 64)]);
        $files[] = $doc;
    }
    $this->actingAs($this->person->user);

    $zip = $this->get('/zip/folders/'.$this->top->id)->assertOk()->streamedContent();
    expect($zip)->toContain('Firma/Projekt Nord/Reisen/Hotel.pdf')->toContain('Firma/Projekt Nord/Hotel.pdf')->not->toContain('Geheim');

    $picked = $this->post('/zip/documents', ['ids' => [$files[0]->id, $files[1]->id, $files[2]->id]])->assertOk()->streamedContent();
    expect($picked)->toContain('Hotel.pdf')->toContain('Hotel (2).pdf')->not->toContain('Geheim');
});
