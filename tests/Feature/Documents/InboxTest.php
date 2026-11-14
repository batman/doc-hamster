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
| Point 153: the Posteingang lists what came in until it is opened, moved or marked "Erledigt";
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

it('keeps a document until it is opened, moved or marked done, and never lists written ones', function (): void {
    $a = inboxUpload($this->colleague, 'a.jpg');
    $b = inboxUpload($this->colleague, 'b.jpg');
    $c = inboxUpload($this->colleague, 'c.jpg');
    $d = inboxUpload($this->colleague, 'd.jpg');
    TextDocument::create($this->owner->org_id, $this->owner->user_id, 'Notiz.txt', false);

    $this->actingAs($this->owner->user)->get('/inbox')->assertOk()
        ->assertSee(['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg'])->assertDontSee('Notiz.txt');

    $folder = Folder::withoutEvents(fn (): Folder => Folder::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->owner->org_id, 'name' => 'Steuer']));
    $this->postJson('/documents/bulk/move', ['ids' => [$b->id], 'folder' => $folder->id])->assertOk();
    $this->get('/files/'.$c->files()->withoutGlobalScope(OrgScope::class)->firstOrFail()->id)->assertOk();
    $this->postJson('/inbox/'.$d->id.'/done')->assertOk();

    $this->get('/inbox')->assertSee('a.jpg')->assertDontSee(['b.jpg', 'c.jpg', 'd.jpg']);

    $this->post('/inbox/done')->assertRedirect('/inbox');
    $this->get('/inbox')->assertDontSee('a.jpg');
    expect($a->fresh()?->inbox_done_at)->not->toBeNull();
});

it('counts new things per source, not own uploads, and a viewed tab starts again from zero', function (): void {
    inboxUpload($this->colleague, 'scan.jpg');
    inboxUpload($this->owner, 'mine.jpg');
    $printed = Document::query()->create(['org_id' => $this->owner->org_id, 'folder_id' => Folder::defaultIdFor($this->owner->org_id),
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
