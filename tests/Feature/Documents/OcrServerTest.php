<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Ocr\OcrLog;
use App\Domain\Ocr\OcrServerSwitch;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Infrastructure\Environment\LocalOcr;
use App\Jobs\Local\RecognizeText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/*
| Point 186: server recognition on/off per organisation, who read a text (Verlauf, OCR log filter).
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    $this->document = Document::query()->withoutGlobalScope(OrgScope::class)
        ->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => 'beleg.jpg']);
    $this->document->forceFill(['ocr_status' => 'pending'])->save();
    DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $this->admin->org_id, 'document_id' => $this->document->id,
        'path' => 'x/beleg.jpg', 'mime' => 'image/jpeg', 'size' => 4, 'sha256' => str_repeat('0', 64)]);
});

it('switches server recognition off and on again', function (): void {
    Queue::fake();
    Cache::put(LocalOcr::MODE_KEY, 'app', 300);
    $ocr = app(LocalOcr::class);
    expect(OcrServerSwitch::enabled($this->admin->org_id))->toBeTrue()->and($ocr->mode($this->admin->org_id))->toBe('app');

    $this->actingAs($this->admin->user)->postJson('/settings/ocr/server', ['server' => '0'])->assertOk()->assertJson(['message' => 'Gespeichert.']);
    Cache::memo()->forget(OcrServerSwitch::key($this->admin->org_id));
    expect(OcrServerSwitch::enabled($this->admin->org_id))->toBeFalse()
        ->and($ocr->mode($this->admin->org_id))->toBe($ocr->browserReady() ? 'browser' : 'none');
    Queue::assertNothingPushed();

    // On again: what waited for the browser goes to the server.
    $this->postJson('/settings/ocr/server', ['server' => '1'])->assertOk();
    expect(OcrServerSwitch::enabled($this->admin->org_id))->toBeTrue();
    Queue::assertPushed(RecognizeText::class, fn (RecognizeText $job): bool => $job->documentId === $this->document->id && ! $job->server);
    expect(OcrLog::query()->withoutGlobalScopes()->where('event', 'server_switched')->count())->toBe(2);
});

it('records who read the text and shows it in the Verlauf', function (): void {
    Cache::put(LocalOcr::MODE_KEY, 'browser', 300);

    $this->actingAs($this->admin->user)->post('/ocr/'.$this->document->id, ['text' => 'Rechnung'])->assertNoContent();
    $this->document->refresh();
    expect($this->document->ocr_engine)->toBe('browser')->and($this->document->ocr_read_at)->not->toBeNull();

    $this->get('/documents/'.$this->document->id)->assertOk()
        ->assertSee('Text erkannt')->assertSee($this->document->ocr_read_at->format('d.m.Y H:i').' (Browser)');
});

it('filters the OCR log by engine', function (): void {
    OcrLog::record($this->admin->org_id, 'browser', 'recognized', ['document_id' => $this->document->id, 'chars' => 11]);
    OcrLog::record($this->admin->org_id, 'app', 'recognized', ['document_id' => $this->document->id, 'chars' => 22]);
    OcrLog::record($this->admin->org_id, 'system', 'recognized', ['document_id' => $this->document->id, 'chars' => 33]);

    $this->actingAs($this->admin->user)->get('/settings/log?books[]=ocr&engine=server')->assertOk()
        ->assertSee('22 Zeichen')->assertSee('33 Zeichen')->assertDontSee('11 Zeichen');
    $this->get('/settings/log?books[]=ocr&engine=browser')->assertOk()
        ->assertSee('11 Zeichen')->assertDontSee('22 Zeichen');
});
