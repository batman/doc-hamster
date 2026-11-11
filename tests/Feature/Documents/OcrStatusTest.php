<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use App\Infrastructure\Environment\OcrProgress;
use App\Jobs\Local\RecognizeText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/*
| Points 35/43, A21-A23: the Texterkennung page, its JSON status and "neu erkennen".
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    $make = fn (string $title, ?string $status) => Document::query()->withoutGlobalScope(OrgScope::class)
        ->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => $title])
        ->forceFill(['ocr_status' => $status])->save();
    $make('a.jpg', 'done');
    $make('b.jpg', 'pending');
    $make('c.jpg', 'failed');
});

it('shows counts and progress on its own settings page and in the JSON status', function (): void {
    $document = Document::query()->withoutGlobalScope(OrgScope::class)->where('title', 'b.jpg')->firstOrFail();
    OcrProgress::start($document);

    $this->actingAs($this->admin->user)->get('/settings/ocr')->assertOk()
        ->assertSee('Texterkennung einrichten')->assertSee('1 von 2 erkannt')->assertSee('Gerade: b.jpg');
    $this->getJson('/ocr/status')->assertOk()
        ->assertJson(['waiting' => 1, 'counts' => ['done' => 1, 'waiting' => 1, 'failed' => 1, 'unavailable' => 0], 'current' => ['title' => 'b.jpg']]);

    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($clerk->user)->get('/settings/ocr')->assertForbidden();
    $this->getJson('/ocr/status')->assertOk()->assertExactJson(['waiting' => 1]);
});

it('recognises all documents again and one from the detail window', function (): void {
    Queue::fake();

    $this->actingAs($this->admin->user)->post('/settings/ocr/rerun')->assertRedirect();
    Queue::assertPushed(RecognizeText::class, 3);
    expect(Document::query()->withoutGlobalScope(OrgScope::class)->where('ocr_status', 'pending')->count())->toBe(3);

    $document = Document::query()->withoutGlobalScope(OrgScope::class)->where('title', 'a.jpg')->firstOrFail();
    $document->forceFill(['ocr_status' => 'failed'])->save();
    $this->get('/documents/'.$document->id)->assertOk()->assertSee('fehlgeschlagen')->assertSee('Neu erkennen');
    $this->post('/documents/'.$document->id.'/ocr')->assertRedirect();
    expect($document->refresh()->ocr_status)->toBe('pending');
});
