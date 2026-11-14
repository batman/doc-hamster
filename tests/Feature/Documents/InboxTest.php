<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Documents\Inbox;
use App\Domain\Documents\TextDocument;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Http\Badges;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
| Point 153, A358-A361: the Posteingang lists what came in until it is filed into a folder (✓ = "Dokumente allgemein");
| the red counts are per source and start again when that tab (or "Alle") is viewed.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    Inbox::forgetSchema();
    $this->owner = Membership::factory()->owner()->create();
    $this->colleague = Membership::factory()->create(['org_id' => $this->owner->org_id]);
});

function inboxUpload(Membership $member, string $name): Document
{
    test()->actingAs($member->user)->post('/documents', ['file' => UploadedFile::fake()->image($name)]);

    return Document::query()->withoutGlobalScope(OrgScope::class)->where('title', $name)->firstOrFail();
}

it('keeps a document until it is filed into a folder; opening changes nothing, written ones never wait', function (): void {
    $a = inboxUpload($this->colleague, 'a.jpg');
    $b = inboxUpload($this->colleague, 'b.jpg');
    $c = inboxUpload($this->colleague, 'c.jpg');
    $d = inboxUpload($this->colleague, 'd.jpg');
    TextDocument::create($this->owner->org_id, $this->owner->user_id, 'Notiz.txt', false);
    expect($a->folder_id)->toBeNull();

    $this->actingAs($this->owner->user)->get('/inbox')->assertOk()
        ->assertSee(['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg'])->assertDontSee('Notiz.txt');
    // A358: the Dokumente top level lists folders only; a search still finds unfiled documents.
    $this->get('/documents')->assertOk()->assertDontSee('a.jpg');
    $this->get('/documents?q=a.jpg')->assertOk()->assertSee('a.jpg');

    $folder = Folder::withoutEvents(fn (): Folder => Folder::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->owner->org_id, 'name' => 'Steuer']));
    $this->postJson('/documents/bulk/move', ['ids' => [$b->id], 'folder' => $folder->id, 'filed' => 1])->assertOk()
        ->assertJson(['message' => 'Abgelegt in „Steuer“', 'moves' => [['id' => $b->id, 'folder' => null, 'trashed' => false]]]);
    $this->get('/files/'.$c->files()->withoutGlobalScope(OrgScope::class)->firstOrFail()->id)->assertOk();
    $this->postJson('/inbox/'.$d->id.'/done')->assertOk();

    $this->get('/inbox')->assertSee(['a.jpg', 'c.jpg'])->assertDontSee(['b.jpg', 'd.jpg']);
    expect($d->fresh()?->folder_id)->toBe(Folder::defaultIdFor($this->owner->org_id));

    // "Rückgängig" of filing: an empty folder takes it back into the Posteingang.
    $this->postJson('/documents/bulk/move', ['ids' => [$b->id], 'folder' => ''])->assertOk()->assertJson(['message' => 'Zurück im Posteingang']);
    $this->get('/inbox')->assertSee('b.jpg');

    $this->post('/inbox/done')->assertRedirect('/inbox');
    $this->get('/inbox')->assertDontSee(['a.jpg', 'b.jpg', 'c.jpg']);
    expect($a->fresh()?->folder_id)->toBe(Folder::defaultIdFor($this->owner->org_id))
        ->and($a->fresh()?->inbox_done_at)->not->toBeNull();
});

it('puts the folders filed into last on top of "Ablegen in …"', function (): void {
    $doc = inboxUpload($this->owner, 'x.jpg');
    $make = fn (string $name): Folder => Folder::withoutEvents(fn (): Folder => Folder::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->owner->org_id, 'name' => $name]));
    $tax = $make('Steuer');
    $car = $make('Auto');
    $make('Haus');

    $this->actingAs($this->owner->user);
    foreach ([$tax, $car, $tax] as $folder) {
        $this->travel(1)->seconds();
        $this->postJson('/documents/bulk/move', ['ids' => [$doc->id], 'folder' => $folder->id])->assertOk();
    }
    $this->postJson('/documents/bulk/move', ['ids' => [$doc->id], 'folder' => null])->assertOk();

    expect(Inbox::recentFolders($this->owner->org_id, $this->owner->user_id))->toBe([$tax->id, $car->id])
        ->and(Inbox::recentFolders($this->owner->org_id, $this->colleague->user_id))->toBe([]);
    $this->get('/inbox')->assertOk()->assertSee('Zuletzt benutzt')->assertSee('data-file-into="'.$car->id.'"', false)->assertSee('Haus');
});

it('keeps what waited in the Posteingang there and files every other folder-less document (migration)', function (): void {
    $default = Folder::defaultIdFor($this->owner->org_id);
    $make = fn (string $title, ?string $folder, array $more = []): Document => Document::query()->withoutGlobalScope(OrgScope::class)
        ->create(['org_id' => $this->owner->org_id, 'folder_id' => $folder, 'uploaded_by' => $this->owner->user_id, 'title' => $title, ...$more]);
    $waiting = $make('wartet.jpg', $default);
    $loose = $make('lose.jpg', null);
    $opened = $make('offen.jpg', null);
    $opened->forceFill(['last_opened_at' => now()])->save();
    $done = $make('erledigt.jpg', $default, ['inbox_done_at' => now()]);

    (require base_path('database/migrations/2026_11_15_120000_inbox_documents_without_folder.php'))->up();

    expect($waiting->fresh()?->folder_id)->toBeNull()
        ->and($loose->fresh()?->folder_id)->toBeNull()
        ->and($opened->fresh()?->folder_id)->toBe($default)
        ->and($done->fresh()?->folder_id)->toBe($default);
});

it('counts new things per source, not own uploads, and a viewed tab starts again from zero', function (): void {
    inboxUpload($this->colleague, 'scan.jpg');
    inboxUpload($this->owner, 'mine.jpg');
    $printed = Document::query()->create(['org_id' => $this->owner->org_id, 'folder_id' => null,
        'uploaded_by' => $this->colleague->user_id, 'title' => 'Druck.pdf', 'in_print_tray' => true]);

    $this->actingAs($this->owner->user)->getJson('/badges')->assertOk()
        ->assertJson(['inbox' => 2, 'inbox-upload' => 1, 'inbox-printer' => 1, 'inbox-phone' => 0]);

    $this->travel(1)->seconds();
    $this->get('/inbox?source=printer')->assertOk()->assertSee('Druck.pdf')->assertDontSee('scan.jpg');
    $this->getJson('/badges')->assertJson(['inbox' => 1, 'inbox-upload' => 1, 'inbox-printer' => 0]);

    // The column comes with the share target; a document shared from a phone counts under Handy.
    if (! Schema::hasColumn('documents', 'source')) {
        Schema::table('documents', fn (Blueprint $table) => $table->string('source', 16)->nullable());
    }
    Inbox::forgetSchema();
    // Changes made elsewhere (here: straight in the database) show once the session's badge counts expire (point 164.4).
    $this->travel(Badges::TTL + 1)->seconds();
    $printed->forceFill(['in_print_tray' => false, 'source' => 'phone', 'created_at' => now()])->save();

    $this->getJson('/badges')->assertJson(['inbox-phone' => 1, 'inbox-printer' => 0]);
    $this->travel(1)->seconds();
    $this->get('/inbox')->assertOk()->assertSee(['scan.jpg', 'mine.jpg', 'Druck.pdf']);
    $this->getJson('/badges')->assertJson(['inbox' => 0, 'inbox-upload' => 0, 'inbox-phone' => 0]);
    Inbox::forgetSchema();
});

it('redirects the old Druckerablage and E-Mail-Ablage', function (): void {
    $this->actingAs($this->owner->user)->get('/mail-tray')->assertRedirect('/inbox?source=mail');
    $this->get('/print-tray')->assertRedirect('/inbox?source=printer');
});
