<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use App\Infrastructure\Environment\LocalOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
| A61: "the browser engine did not start" is text from a person's browser. It is kept per organisation:
| nobody sees, sets or clears the message of another organisation.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Cache::put(LocalOcr::MODE_KEY, 'browser', 300);
    $this->ours = Membership::factory()->owner()->create();
    $this->theirs = Membership::factory()->owner()->create();
    // Made before the first request: afterwards one organisation is active and rows of the other are refused.
    $document = static fn (Membership $owner): Document => Document::query()->withoutGlobalScope(OrgScope::class)
        ->create(['org_id' => $owner->org_id, 'uploaded_by' => $owner->user_id, 'title' => 'bon.jpg']);
    $this->ourDocument = $document($this->ours);
    $this->theirDocument = $document($this->theirs);
});

it('shows the engine message only inside the organisation it was reported in', function (): void {
    // Any member's browser can be the one that fails, also one with only "Belege hochladen".
    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->ours->org_id]);
    $this->actingAs($clerk->user)->post('/ocr-engine', ['reason' => 'CompileError: wasm blocked'])->assertNoContent();

    $this->actingAs($this->ours->user)->get('/settings/ocr')->assertOk()->assertSee('wasm blocked');
    $this->get('/documents')->assertOk()->assertSee('wasm blocked');
    $this->getJson('/ocr/status')->assertOk()->assertJson(['engine_error' => 'CompileError: wasm blocked']);

    $this->actingAs($this->theirs->user)->get('/settings/ocr')->assertOk()->assertDontSee('wasm blocked');
    $this->get('/documents')->assertOk()->assertDontSee('wasm blocked');
    $this->getJson('/ocr/status')->assertOk()->assertJsonMissingPath('engine_error');
});

it('clears the message only when a browser of the same organisation reads again', function (): void {
    $this->actingAs($this->ours->user)->post('/ocr-engine', ['reason' => 'CompileError: wasm blocked'])->assertNoContent();

    $this->actingAs($this->theirs->user)->post('/ocr/'.$this->theirDocument->id, ['text' => 'Summe 12,00', 'pass' => 1])->assertNoContent();
    expect(app(LocalOcr::class)->browserError($this->ours->org_id))->toBe('CompileError: wasm blocked');

    $this->actingAs($this->ours->user)->post('/ocr/'.$this->ourDocument->id, ['text' => 'Summe 12,00', 'pass' => 1])->assertNoContent();
    expect(app(LocalOcr::class)->browserError($this->ours->org_id))->toBeNull();
});
