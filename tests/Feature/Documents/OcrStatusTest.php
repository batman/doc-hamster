<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\OcrProgress;
use App\Jobs\Local\RecognizeText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        ->assertSee('Erkennung auf dem Server einrichten')->assertSee('1 von 2 erkannt')->assertSee('Gerade: b.jpg');
    $this->getJson('/ocr/status')->assertOk()
        ->assertJson(['waiting' => 1, 'open' => 1, 'total' => 2, 'passes' => 3, 'counts' => ['done' => 1, 'waiting' => 1, 'failed' => 1, 'unavailable' => 0], 'current' => ['title' => 'b.jpg']]);

    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($clerk->user)->get('/settings/ocr')->assertForbidden();
    $this->getJson('/ocr/status')->assertOk()->assertExactJson(['waiting' => 1, 'open' => 1, 'total' => 2, 'passes' => 3]);
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

it('reads in three passes in the browser, the lowest pass first, and shows the indicator below Abmelden', function (): void {
    Cache::put(LocalOcr::MODE_KEY, 'browser', 300);
    $documents = Document::query()->withoutGlobalScope(OrgScope::class)->get()->keyBy('title');
    foreach ($documents as $document) {
        DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $document->org_id, 'document_id' => $document->id, 'path' => 'x/'.$document->title, 'mime' => 'image/jpeg', 'size' => 4, 'sha256' => str_repeat('0', 64)]);
    }
    $a = $documents['a.jpg'];
    $a->forceFill(['ocr_pass' => 1, 'ocr_text' => 'grob'])->save();
    $b = $documents['b.jpg'];

    $this->actingAs($this->admin->user)->get('/')->assertOk()->assertSee('OCR-Erkennung')->assertSee('2 offen');
    $this->getJson('/ocr/next')->assertOk()->assertJson(['id' => $b->id, 'pass' => 1]);
    $this->post('/ocr/'.$b->id, ['text' => 'erster', 'pass' => 1])->assertNoContent();
    expect($b->refresh()->ocr_status)->toBe('done')->and($b->ocr_pass)->toBe(1);
    expect(OcrProgress::counts($this->admin->org_id)['done'])->toBe(2);

    $this->getJson('/ocr/next?skip[]='.$b->id)->assertOk()->assertJson(['id' => $a->id, 'pass' => 2]);
    // Point 58: a failed pass comes again; after three failures the earlier text stays final.
    $this->post('/ocr/'.$a->id, ['failed' => 1, 'pass' => 2])->assertNoContent();
    expect($a->refresh()->ocr_pass)->toBe(1)->and($a->ocr_failures)->toBe(1);
    $this->post('/ocr/'.$a->id, ['failed' => 1, 'pass' => 2])->assertNoContent();
    $this->post('/ocr/'.$a->id, ['failed' => 1, 'pass' => 2])->assertNoContent();
    expect($a->refresh()->ocr_text)->toBe('grob')->and($a->ocr_pass)->toBe(3)->and($a->ocr_status)->toBe('done');
    // A84: a pass already reached is not stored again.
    $this->post('/ocr/'.$a->id, ['text' => 'voll', 'pass' => 3])->assertOk()->assertJson(['ignored' => true]);
    expect($a->refresh()->ocr_text)->toBe('grob');
    $this->post('/ocr/'.$b->id, ['text' => 'voll', 'pass' => 3])->assertNoContent();
    $this->getJson('/ocr/next')->assertNoContent();
    $this->getJson('/ocr/status')->assertJson(['open' => 0, 'total' => 2, 'passes' => 6]);
});

it('marks a document without text failed after three failures and shows engine errors and the cron state', function (): void {
    Cache::put(LocalOcr::MODE_KEY, 'browser', 300);
    $document = Document::query()->withoutGlobalScope(OrgScope::class)->where('title', 'b.jpg')->firstOrFail();

    $this->actingAs($this->admin->user);
    foreach ([1, 2, 3] as $try) {
        $this->post('/ocr/'.$document->id, ['failed' => 1, 'pass' => 1])->assertNoContent();
    }
    expect($document->refresh()->ocr_status)->toBe('failed');

    $this->post('/ocr-engine', ['reason' => 'CompileError: wasm blocked'])->assertNoContent();
    $this->get('/settings/ocr')->assertOk()->assertSee('wasm blocked')->assertSee('Kein Cronjob')->assertSee('schedule:run');
    $this->getJson('/ocr/status')->assertJson(['engine_error' => 'CompileError: wasm blocked']);

    Cache::forever(LocalOcr::STATUS_KEY, ['state' => 'running', 'started' => time() - 700]);
    expect(app(LocalOcr::class)->status())->toBe([]);
    $this->postJson('/queue/tick')->assertOk()->assertJson(['cron' => false]);
});

it('logs browser results, engine and pause events and cron, shows them on the Protokoll tab and keeps 30 days', function (): void {
    Cache::put(LocalOcr::MODE_KEY, 'browser', 300);
    $document = Document::query()->withoutGlobalScope(OrgScope::class)->where('title', 'b.jpg')->firstOrFail();

    $this->actingAs($this->admin->user);
    $this->post('/ocr/'.$document->id, ['failed' => 1, 'pass' => 1, 'reason' => 'timeout', 'duration' => 120000])->assertNoContent();
    $this->post('/ocr/'.$document->id, ['text' => 'Summe 12,00', 'pass' => 1, 'duration' => 2500])->assertNoContent();
    $this->post('/ocr/'.$document->id, ['text' => 'nochmal', 'pass' => 1])->assertOk()->assertJson(['ignored' => true]);
    $this->post('/ocr-log', ['event' => 'paused'])->assertNoContent();
    $this->post('/ocr-log', ['event' => 'nonsense'])->assertSessionHasErrors('event');
    $this->getJson('/ocr/status')->assertOk();
    $this->getJson('/ocr/status')->assertOk();

    expect(OcrLog::query()->pluck('event')->sort()->values()->all())->toBe(['cron_missing', 'paused', 'recognized', 'timeout']);
    expect(OcrLog::query()->where('event', 'recognized')->firstOrFail())
        ->chars->toBe(11)->duration_ms->toBe(2500)->pass->toBe(1)->document_id->toBe($document->id);

    $this->get('/settings/ocr?tab=log')->assertOk()->assertSee('Protokoll')->assertSee('b.jpg')
        ->assertSee('erkannt · 11 Zeichen')->assertSee('Zeitlimit')->assertSee('pausiert')->assertDontSee('Summe 12,00');
    $this->get('/settings/ocr?tab=log&errors=1')->assertOk()->assertSee('Zeitlimit')->assertDontSee('erkannt · 11 Zeichen');

    OcrLog::query()->where('event', 'paused')->toBase()->update(['created_at' => now()->subDays(31)]);
    expect(OcrLog::prune())->toBe(1);
});

it('hands out a random document of the lowest pass and only pass 1 to the second engine', function (): void {
    Cache::put(LocalOcr::MODE_KEY, 'browser', 300);
    $document = Document::query()->withoutGlobalScope(OrgScope::class)->where('title', 'a.jpg')->firstOrFail();
    DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $document->org_id, 'document_id' => $document->id, 'path' => 'x/a.jpg', 'mime' => 'image/jpeg', 'size' => 4, 'sha256' => str_repeat('0', 64)]);
    $document->forceFill(['ocr_pass' => 2, 'ocr_text' => 'x'])->save();

    $this->actingAs($this->admin->user);
    $this->getJson('/ocr/next')->assertOk()->assertJson(['id' => $document->id, 'pass' => 3]);
    $this->getJson('/ocr/next?only=1')->assertNoContent();
});
