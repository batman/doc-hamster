<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\ShareLink;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
| Missing share-link cases from #56, locked after the #58 fixes, plus
| adversarial coverage for the share routes.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->doc = Document::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => 'bon.jpg']);
    Storage::disk('local')->put('x/bon.jpg', 'bild');
    $this->file = DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->admin->org_id, 'document_id' => $this->doc->id, 'path' => 'x/bon.jpg', 'mime' => 'image/jpeg', 'size' => 4, 'sha256' => str_repeat('0', 64)]);
});

it('refuses a share link after expiry', function (): void {
    $url = $this->actingAs($this->admin->user)->post('/documents/'.$this->doc->id.'/share-link')->assertRedirect()->getSession()->get('share_url');
    auth()->logout();

    $this->get($url)->assertOk();
    $this->travel(ShareLink::DEFAULT_DAYS + 1)->days();
    $this->get($url)->assertNotFound();
});

it('refuses a share link to a receipt in the trash, and hands it out again after restore', function (): void {
    $url = $this->actingAs($this->admin->user)->post('/documents/'.$this->doc->id.'/share-link')->assertRedirect()->getSession()->get('share_url');

    $this->post('/documents/'.$this->doc->id.'/delete')->assertRedirect();
    auth()->logout();
    $this->get($url)->assertNotFound();

    $this->doc->restore();
    $this->get($url)->assertOk();
});

it('hides other receipts from a member without see-all in the share list', function (): void {
    $exporter = Membership::factory()->withRights(RoleRight::Upload, RoleRight::Export)->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($this->admin->user)->post('/documents/'.$this->doc->id.'/share-link')->assertRedirect();
    $link = ShareLink::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();

    $this->actingAs($exporter->user)->get('/share-links')->assertOk()->assertDontSee('bon.jpg');
    $this->post('/share-links/'.$link->id.'/revoke')->assertNotFound();
    expect($link->fresh()->revoked_at)->toBeNull();

    $this->actingAs($this->admin->user)->get('/share-links')->assertOk()->assertSee('bon.jpg');
});

it('downloads a title containing a slash without failing', function (): void {
    $this->doc->update(['title' => 'Rechnung 03/2026']);

    $this->actingAs($this->admin->user)->get('/files/'.$this->file->id.'/download')->assertOk()->assertDownload('Rechnung 03-2026.jpg');

    $url = $this->post('/documents/'.$this->doc->id.'/share-link')->assertRedirect()->getSession()->get('share_url');
    auth()->logout();
    $this->get($url)->assertOk()->assertDownload('Rechnung 03-2026.jpg');
});

it('refuses a revoked link, and revoking twice stays revoked', function (): void {
    $url = $this->actingAs($this->admin->user)->post('/documents/'.$this->doc->id.'/share-link')->assertRedirect()->getSession()->get('share_url');
    $link = ShareLink::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();

    $this->post('/share-links/'.$link->id.'/revoke')->assertRedirect(route('shares.index'));
    $this->post('/share-links/'.$link->id.'/revoke')->assertRedirect(route('shares.index'));
    expect($link->fresh()->revoked_at)->not->toBeNull();

    auth()->logout();
    $this->get($url)->assertNotFound();
});

it('refuses unknown and tampered tokens', function (): void {
    $url = $this->actingAs($this->admin->user)->post('/documents/'.$this->doc->id.'/share-link')->assertRedirect()->getSession()->get('share_url');
    auth()->logout();

    $token = (string) str($url)->afterLast('/');
    $tampered = substr($token, 0, -1).($token[-1] === 'a' ? 'b' : 'a');

    $this->get($url)->assertOk();
    $this->get('/s/'.$tampered)->assertNotFound();
    $this->get('/s/'.str_repeat('a', 40))->assertNotFound();
});

it('keeps share links inside their organisation', function (): void {
    $this->actingAs($this->admin->user)->post('/documents/'.$this->doc->id.'/share-link')->assertRedirect();
    $link = ShareLink::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();

    $foreign = Membership::factory()->owner()->create();
    $this->actingAs($foreign->user)->get('/share-links')->assertOk()->assertDontSee('bon.jpg');
    $this->post('/share-links/'.$link->id.'/revoke')->assertNotFound();
    expect($link->fresh()->revoked_at)->toBeNull();
});

it('locks a revoked member out of the share list', function (): void {
    $exporter = Membership::factory()->withRights(RoleRight::Upload, RoleRight::Export)->create(['org_id' => $this->admin->org_id]);
    $exporter->update(['revoked_at' => now()]);

    $this->actingAs($exporter->user)->get('/share-links')->assertForbidden();
});
