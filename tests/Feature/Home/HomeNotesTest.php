<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogEntry;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\ShareLink;
use App\Domain\Notes\Note;
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
    $this->post('/notes', ['body' => 'Für alle', 'shared' => 1, 'checklist' => "Milch\n[x] Brot", 'link' => 'bon.jpg'])->assertRedirect();

    $note = Note::query()->withoutGlobalScope(OrgScope::class)->where('body', 'Für alle')->firstOrFail();
    expect($note->checklist)->toEqual([['text' => 'Milch', 'done' => false], ['text' => 'Brot', 'done' => true]])
        ->and($note->documents()->pluck('documents.id')->all())->toBe([$this->doc->id]);

    // The clerk may not see the Admin's receipt, so the shared note doesn't name it (#56).
    $this->actingAs($clerk->user)->get('/')->assertSee('Für alle')->assertDontSee('bon.jpg');
    $this->post('/notes/'.$note->id.'/check', ['done' => [0]])->assertForbidden();

    $this->actingAs($this->admin->user)->post('/notes/'.$note->id, ['body' => 'Für alle', 'shared' => 1, 'editable' => 1, 'checklist' => "Milch\n[x] Brot"]);
    $this->actingAs($clerk->user)->post('/notes/'.$note->id.'/check', ['done' => [0, 1]])->assertRedirect();
    expect(array_column($note->fresh()->checklist, 'done'))->toBe([true, true]);
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
    $this->post('/notes/'.$note->id.'/look', ['color' => 'blue', 'opacity' => 60])->assertRedirect(route('home'));
    $this->post('/notes/'.$note->id.'/look', ['color' => 'black'])->assertSessionHasErrors('color');
    expect($note->fresh()->only(['title', 'color', 'opacity', 'pos_x', 'pos_y']))
        ->toBe(['title' => 'Einkauf', 'color' => 'blue', 'opacity' => 60, 'pos_x' => 120, 'pos_y' => 40]);
    $this->get('/')->assertSee('Einkauf')->assertSee('note-blue')->assertSee('data-x="120"', false);

    $this->post('/notes/'.$note->id.'/look', ['locked' => 1])->assertRedirect();
    $this->post('/notes/'.$note->id, ['body' => 'Anders'])->assertStatus(423);
    $this->postJson('/notes/'.$note->id.'/look', ['x' => 0, 'y' => 0])->assertStatus(423);
    $this->post('/notes/'.$note->id.'/delete')->assertStatus(423);
    $this->get('/')->assertSee('Milch')->assertDontSee('data-note-look', false);

    $this->post('/notes/'.$note->id.'/look', ['locked' => 0])->assertRedirect();
    $this->post('/notes/'.$note->id.'/delete')->assertRedirect();
    expect(Note::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(0);
});
