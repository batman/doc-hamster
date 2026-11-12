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
    $this->get('/settings/ocr?tab=log&result=timeout')->assertOk()->assertSee('Zeitlimit')->assertDontSee('erkannt · 11 Zeichen');

    OcrLog::query()->where('event', 'paused')->toBase()->update(['created_at' => now()->subDays(31)]);
    expect(OcrLog::prune())->toBe(1);
});

it('logs file details, browser and reasons once per document, filters and sums up the log (point 69)', function (): void {
    Cache::put(LocalOcr::MODE_KEY, 'browser', 300);
    $documents = Document::query()->withoutGlobalScope(OrgScope::class)->get()->keyBy('title');
    $file = fn (Document $d, string $mime) => DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $d->org_id, 'document_id' => $d->id, 'path' => 'x/'.$d->id, 'mime' => $mime, 'size' => 2_500_000, 'sha256' => str_repeat('0', 64)]);
    $file($documents['a.jpg'], 'image/heic');
    $file($documents['b.jpg'], 'application/pdf');

    // A108: HEIC cannot be read – logged once with its reason; the PDF waits for the browser without an entry.
    (new RecognizeText($documents['a.jpg']->id))->handle(app(LocalOcr::class));
    (new RecognizeText($documents['a.jpg']->id))->handle(app(LocalOcr::class));
    (new RecognizeText($documents['b.jpg']->id))->handle(app(LocalOcr::class));
    expect($documents['a.jpg']->refresh()->ocr_status)->toBe('unavailable')
        ->and($documents['b.jpg']->refresh()->ocr_status)->toBe('pending')
        ->and(OcrLog::query()->withoutGlobalScope(OrgScope::class)->where('event', 'unavailable')->pluck('message')->all())->toBe(['HEIC im Browser nicht lesbar']);

    // A106/A109/A114: the browser took the text layer of both pages.
    $this->actingAs($this->admin->user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36')
        ->post('/ocr/'.$documents['b.jpg']->id, ['text' => 'Rechnung', 'pass' => 1, 'pages' => 2, 'layer' => 2, 'duration' => 900])->assertNoContent();
    expect(OcrLog::query()->withoutGlobalScope(OrgScope::class)->where('event', 'text_layer')->firstOrFail())
        ->file_type->toBe('PDF')->size->toBe(2_500_000)->pages->toBe(2)->agent->toBe('Chrome 142 / Windows');

    // A110/A112: filters and the summary of "nicht möglich" by reason.
    $this->get('/settings/ocr?tab=log')->assertOk()->assertSee('Nicht möglich – nach Grund')
        ->assertSee('Textebene übernommen · 8 Zeichen')->assertSee('PDF · 2,4 MB · 2 Seiten')->assertSee('Chrome 142 / Windows');
    $this->get('/settings/ocr?tab=log&result=unavailable')->assertOk()->assertSee('HEIC im Browser nicht lesbar')->assertDontSee('Textebene übernommen');
    $this->get('/settings/ocr?tab=log&engine=browser&q=b.jpg')->assertOk()->assertSee('Textebene übernommen')->assertDontSee('nicht möglich: HEIC');
    $this->get('/settings/ocr?tab=log&doc='.$documents['a.jpg']->id)->assertOk()->assertSee('Dokument: a.jpg')->assertDontSee('Textebene übernommen');

    // A111: "Alle neu erkennen" with count and who.
    Queue::fake();
    $this->post('/settings/ocr/rerun')->assertRedirect();
    expect(OcrLog::query()->withoutGlobalScope(OrgScope::class)->where('event', 'rerun')->firstOrFail())->message->toBe('3')->user_id->toBe($this->admin->user_id);
});

it('lets the browser read only the PDFs while the app-owned server engine has no PDF converter', function (): void {
    Cache::put(LocalOcr::MODE_KEY, 'app', 300);
    $documents = Document::query()->withoutGlobalScope(OrgScope::class)->get()->keyBy('title');
    foreach (['a.jpg' => 'image/jpeg', 'b.jpg' => 'application/pdf'] as $title => $mime) {
        DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $documents[$title]->org_id, 'document_id' => $documents[$title]->id, 'path' => 'x/'.$title, 'mime' => $mime, 'size' => 4, 'sha256' => str_repeat('0', 64)]);
    }
    $documents['a.jpg']->forceFill(['ocr_status' => 'pending'])->save();

    expect(app(LocalOcr::class)->browserLoop())->toBeTrue();
    $this->actingAs($this->admin->user)->getJson('/ocr/next?skip[]=x')->assertOk()->assertJson(['id' => $documents['b.jpg']->id, 'type' => 'application/pdf']);
    $this->getJson('/ocr/next?skip[]='.$documents['b.jpg']->id)->assertNoContent();
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
