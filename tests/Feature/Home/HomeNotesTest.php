<?php

declare(strict_types=1);

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
    expect($note->checklist)->toBe([['text' => 'Milch', 'done' => false], ['text' => 'Brot', 'done' => true]])
        ->and($note->documents()->pluck('documents.id')->all())->toBe([$this->doc->id]);

    $this->actingAs($clerk->user)->get('/')->assertSee('Für alle')->assertSee('bon.jpg');
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
