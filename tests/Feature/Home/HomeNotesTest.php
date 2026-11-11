<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogEntry;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\ShareLink;
use App\Domain\Documents\Tag;
use App\Domain\Notes\Note;
use App\Domain\Notes\NoteColumn;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
| Go of 2026-11-04: sticky notes on Home, sending by link, the trash.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->doc = Document::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => 'bon.jpg']);
});

it('keeps a quick note private, and with the right shows it to everyone', function (): void {
    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);

    $this->actingAs($clerk->user)->post('/notes', ['body' => 'Nur für mich', 'shared' => 1])->assertRedirect(route('home'));
    expect(Note::query()->withoutGlobalScope(OrgScope::class)->firstOrFail()->shared)->toBeFalse();

    $this->actingAs($this->admin->user)->get('/')->assertOk()->assertDontSee('Nur für mich');
    $this->post('/notes', ['body' => 'Für alle [Milch, #Brot]', 'shared' => 1, 'link' => 'bon.jpg'])->assertRedirect();

    $note = Note::query()->withoutGlobalScope(OrgScope::class)->where('body', 'like', 'Für alle%')->firstOrFail();
    expect($note->documents()->pluck('documents.id')->all())->toBe([$this->doc->id]);

    // The clerk may not see the Admin's receipt, so the shared note doesn't name it (#56).
    $this->actingAs($clerk->user)->get('/')->assertSee('Für alle')->assertDontSee('bon.jpg');
    $this->postJson('/notes/'.$note->id.'/text', ['body' => 'Für alle [#Milch, #Brot]'])->assertForbidden();

    // The edit dialog sends no text, so the text stays; then others may tick (write the text).
    $this->actingAs($this->admin->user)->post('/notes/'.$note->id, ['shared' => 1, 'editable' => 1]);
    expect($note->fresh()->body)->toBe('Für alle [Milch, #Brot]');
    $this->actingAs($clerk->user)->postJson('/notes/'.$note->id.'/text', ['body' => 'Für alle [#Milch, #Brot]'])->assertOk();
    expect($note->fresh()->body)->toBe('Für alle [#Milch, #Brot]');
});

it('changes the text on the note and keeps its size', function (): void {
    $this->actingAs($this->admin->user)->post('/notes', ['body' => 'alt']);
    $note = Note::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();

    $this->postJson('/notes/'.$note->id.'/text', ['body' => 'neu [x, #y]'])->assertOk();
    $this->postJson('/notes/'.$note->id.'/look', ['w' => 300, 'h' => 200])->assertOk();
    $this->postJson('/notes/'.$note->id.'/look', ['w' => 50])->assertStatus(422);
    expect($note->fresh()->only(['body', 'width', 'height']))->toBe(['body' => 'neu [x, #y]', 'width' => 300, 'height' => 200]);
    $this->get('/')->assertSee('data-h="200"', false);
});

it('hands out a receipt by link until it is revoked', function (): void {
    Storage::disk('local')->put('x/bon.jpg', 'bild');
    DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->admin->org_id, 'document_id' => $this->doc->id, 'path' => 'x/bon.jpg', 'mime' => 'image/jpeg', 'size' => 4, 'sha256' => str_repeat('0', 64)]);

    $url = $this->actingAs($this->admin->user)->post('/documents/'.$this->doc->id.'/share-link')->assertRedirect()->getSession()->get('share_url');
    auth()->logout();

    $this->get($url)->assertOk()->assertDownload('bon.jpg');
    ShareLink::query()->withoutGlobalScope(OrgScope::class)->update(['revoked_at' => now()]);
    $this->get($url)->assertNotFound();
    $this->get('/s/'.str_repeat('a', 40))->assertNotFound();
});

it('brings a receipt back from the trash', function (): void {
    $this->actingAs($this->admin->user)->post('/documents/'.$this->doc->id.'/delete');
    $this->get('/documents/trash')->assertOk()->assertSee('bon.jpg');

    $this->post('/documents/'.$this->doc->id.'/restore')->assertRedirect(route('documents.trash'));
    $this->get('/documents')->assertSee('bon.jpg');
});

it('names the download after the title, without slashes and with the file type', function (): void {
    Storage::disk('local')->put('x/bon.jpg', 'bild');
    $file = DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->admin->org_id, 'document_id' => $this->doc->id, 'path' => 'x/bon.jpg', 'mime' => 'image/jpeg', 'size' => 4, 'sha256' => str_repeat('0', 64)]);
    $this->doc->update(['title' => 'Rechnung 03/2026']);

    $this->actingAs($this->admin->user)->get('/files/'.$file->id.'/download')->assertOk()->assertDownload('Rechnung 03-2026.jpg');

    $url = $this->post('/documents/'.$this->doc->id.'/share-link')->getSession()->get('share_url');
    auth()->logout();
    $this->get($url)->assertOk()->assertDownload('Rechnung 03-2026.jpg');

    expect(AuditLogEntry::query()->where('action', 'document.share_opened')->count())->toBe(1);
});

it('stops a link when it expires or its receipt is in the trash', function (): void {
    Storage::disk('local')->put('x/bon.jpg', 'bild');
    DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->admin->org_id, 'document_id' => $this->doc->id, 'path' => 'x/bon.jpg', 'mime' => 'image/jpeg', 'size' => 4, 'sha256' => str_repeat('0', 64)]);
    $url = $this->actingAs($this->admin->user)->post('/documents/'.$this->doc->id.'/share-link')->getSession()->get('share_url');

    $this->post('/documents/'.$this->doc->id.'/delete');
    auth()->logout();
    $this->get($url)->assertNotFound();

    $this->doc->restore();
    $this->get($url)->assertOk();
    $this->travel(ShareLink::DEFAULT_DAYS + 1)->days();
    $this->get($url)->assertNotFound();
});

it('lists and revokes only links to receipts the person may see, and only Admins set the lifetime', function (): void {
    $exporter = Membership::factory()->withRights(RoleRight::Upload, RoleRight::Export)->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($this->admin->user)->post('/documents/'.$this->doc->id.'/share-link');
    $link = ShareLink::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();

    $this->actingAs($exporter->user)->get('/share-links')->assertOk()->assertDontSee('bon.jpg')->assertSee('7');
    $this->post('/share-links/'.$link->id.'/revoke')->assertNotFound();
    $this->post('/share-links/days', ['days' => 365])->assertForbidden();
    expect($link->fresh()->revoked_at)->toBeNull();

    $this->actingAs($this->admin->user)->get('/share-links')->assertSee('bon.jpg');
    $this->post('/share-links/days', ['days' => 30])->assertRedirect(route('shares.index'));
    $this->post('/share-links/'.$link->id.'/revoke')->assertRedirect(route('shares.index'));
    expect($link->fresh()->revoked_at)->not->toBeNull();
});

it('keeps title, colour, opacity and place of a note, and a locked note stays as it is', function (): void {
    $this->actingAs($this->admin->user)->post('/notes', ['title' => 'Einkauf', 'body' => 'Milch'])->assertRedirect();
    $note = Note::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();

    $this->postJson('/notes/'.$note->id.'/look', ['x' => 120, 'y' => 40])->assertOk();
    $this->post('/notes/'.$note->id, ['title' => 'Einkauf', 'body' => 'Milch', 'color' => '#0d1b4c', 'opacity' => 60])->assertRedirect(route('home'));
    $this->post('/notes/'.$note->id.'/look', ['color' => 'rainbow'])->assertSessionHasErrors('color');
    expect($note->fresh()->only(['title', 'color', 'opacity', 'pos_x', 'pos_y']))
        ->toBe(['title' => 'Einkauf', 'color' => '#0d1b4c', 'opacity' => 60, 'pos_x' => 120, 'pos_y' => 40]);
    $this->get('/')->assertSee('Einkauf')->assertSee('c-0d1b4c');

    // Locked in the edit dialog (A13): the fields stay as they are, only the lock can change.
    $this->post('/notes/'.$note->id, ['title' => 'Einkauf', 'body' => 'Milch', 'locked' => 1])->assertRedirect();
    $this->post('/notes/'.$note->id, ['title' => 'Anders', 'body' => 'Anders', 'locked' => 1])->assertRedirect();
    expect($note->fresh()->only(['title', 'body', 'locked']))->toBe(['title' => 'Einkauf', 'body' => 'Milch', 'locked' => true]);
    $this->postJson('/notes/'.$note->id.'/look', ['x' => 0, 'y' => 0])->assertStatus(423);
    $this->post('/notes/'.$note->id.'/delete')->assertStatus(423);
    $this->get('/')->assertSee('Milch')->assertSee('note-lock')->assertDontSee('data-note-look', false);

    $this->post('/notes/'.$note->id, [])->assertRedirect();
    expect($note->fresh()->only(['body', 'locked']))->toBe(['body' => 'Milch', 'locked' => false]);
    $this->post('/notes/'.$note->id.'/delete')->assertRedirect();
    expect(Note::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(0);
});

it('puts notes on a board of columns, moves, archives and tags them', function (): void {
    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);
    $tag = Tag::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $this->admin->org_id)->firstOrFail();

    // The board starts with one column; new notes go to its end.
    $this->actingAs($this->admin->user)->get('/')->assertOk()->assertSee('Notizen');
    $first = NoteColumn::query()->withoutGlobalScope(OrgScope::class)->sole();
    $this->post('/notes', ['title' => 'A', 'tags' => [$tag->id]]);
    $this->post('/notes', ['title' => 'B']);
    $a = Note::query()->withoutGlobalScope(OrgScope::class)->where('title', 'A')->firstOrFail();
    $b = Note::query()->withoutGlobalScope(OrgScope::class)->where('title', 'B')->firstOrFail();
    expect([$a->column_id, $a->sort, $b->sort])->toBe([$first->id, 1, 2])
        ->and($a->tags()->pluck('tags.id')->all())->toBe([$tag->id]);

    // Columns: any number, renamed, ordered; only with the right.
    $this->post('/notes/columns', ['name' => 'Erledigt'])->assertRedirect(route('home'));
    $done = NoteColumn::query()->withoutGlobalScope(OrgScope::class)->where('name', 'Erledigt')->firstOrFail();
    $this->postJson('/notes/columns/'.$done->id, ['name' => 'Fertig'])->assertOk();
    $this->postJson('/notes/columns/order', ['order' => [$done->id, $first->id]])->assertOk();
    expect($done->fresh()->only(['name', 'sort']))->toBe(['name' => 'Fertig', 'sort' => 0]);
    $this->actingAs($clerk->user)->post('/notes/columns', ['name' => 'X'])->assertForbidden();

    // Drag & drop: B goes before A into "Fertig".
    $this->actingAs($this->admin->user)->postJson('/notes/'.$b->id.'/move', ['column' => $done->id, 'order' => [$b->id]])->assertOk();
    $this->postJson('/notes/'.$a->id.'/move', ['column' => $done->id, 'order' => [$b->id, $a->id]])->assertOk();
    expect([$b->fresh()->sort, $a->fresh()->sort, $a->fresh()->column_id])->toBe([0, 1, $done->id]);

    // The tag filter shows only A; the pill links to the receipts with that tag.
    $this->get('/?tag='.$tag->id)->assertSee('>A<', false)->assertDontSee('>B<', false);

    // Archive and back; deleting a column moves its notes to the first other one.
    $this->post('/notes/'.$a->id.'/archive')->assertRedirect(route('home'));
    $this->get('/')->assertDontSee('>A<', false);
    $this->get('/notes/archive')->assertOk()->assertSee('A');
    $this->post('/notes/'.$a->id.'/restore')->assertRedirect(route('notes.archive'));
    $this->post('/notes/columns/'.$done->id.'/delete')->assertRedirect(route('home'));
    expect($a->fresh()->column_id)->toBe($first->id)->and($a->fresh()->archived_at)->toBeNull();
    $this->post('/notes/columns/'.$first->id.'/delete')->assertStatus(422);
});
