<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use App\Events\TextRecognized;
use App\Infrastructure\Environment\LocalOcr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/*
| Point 119 / F17: word positions from the browser, the words route, and the re-read of all documents behind new ones.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->admin = Membership::factory()->owner()->create();
    $this->make = function (string $title, ?string $status, array $values = []): Document {
        $document = Document::query()->withoutGlobalScope(OrgScope::class)
            ->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => $title]);
        $document->forceFill(['ocr_status' => $status, ...$values])->save();
        DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $document->org_id, 'document_id' => $document->id, 'path' => 'x/'.$title, 'mime' => 'image/jpeg', 'size' => 4, 'sha256' => str_repeat('0', 64)]);

        return $document;
    };
});

it('stores the browser word positions, announces the text and serves them with corrections to who may see the document', function (): void {
    Event::fake([TextRecognized::class]);
    $document = ($this->make)('a.jpg', 'pending');
    $words = ['source' => 'browser', 'pages' => [['n' => 1, 'words' => [
        ['t' => 'Summe', 'x' => 0.1, 'y' => 0.5, 'w' => 0.1, 'h' => 0.02, 'c' => 91, 'l' => 0, 'b' => 0],
        ['t' => '12,0O', 'x' => 0.3, 'y' => 0.5, 'w' => 0.1, 'h' => 0.02, 'c' => 40, 'l' => 0, 'b' => 0],
        ['t' => '', 'x' => 2],
        'junk',
    ]]]];

    $this->actingAs($this->admin->user)->post('/ocr/'.$document->id, ['text' => 'Summe 12,0O', 'pass' => 1, 'words' => json_encode($words)])->assertNoContent();
    Event::assertDispatched(TextRecognized::class, static fn (TextRecognized $e): bool => $e->documentId === $document->id);
    $document->refresh()->forceFill(['ocr_fixes' => json_encode([['p' => 1, 'x' => 0.29, 'y' => 0.49, 'w' => 0.12, 'h' => 0.04, 't' => '12,00', 'o' => '12,0O']])])->save();

    $this->getJson('/documents/'.$document->id.'/words')->assertOk()
        ->assertJsonCount(2, 'pages.0.words')
        ->assertJsonPath('pages.0.words.1.t', '12,00')
        ->assertJsonPath('pages.0.words.0', ['t' => 'Summe', 'x' => 0.1, 'y' => 0.5, 'w' => 0.1, 'h' => 0.02, 'c' => 91, 'l' => 0, 'b' => 0]);

    // Uploaders without "view all" see only their own documents; another organisation sees nothing.
    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);
    $this->actingAs($clerk->user)->getJson('/documents/'.$document->id.'/words')->assertNotFound();
    $stranger = Membership::factory()->owner()->create();
    $this->actingAs($stranger->user)->getJson('/documents/'.$document->id.'/words')->assertNotFound();
});

it('puts every document back for a re-read behind new uploads, keeping the old text meanwhile', function (): void {
    Cache::put(LocalOcr::MODE_KEY, 'browser', 300);
    $read = ($this->make)('read.jpg', 'done', ['ocr_text' => 'alt', 'ocr_pass' => 3]);
    $failed = ($this->make)('failed.jpg', 'failed', ['ocr_failures' => 3]);
    (require database_path('migrations/2026_11_13_140100_rescan_all_documents.php'))->up();

    expect($read->refresh()->ocr_status)->toBe('done')->and($read->ocr_text)->toBe('alt')->and((bool) $read->ocr_rescan)->toBeTrue();
    expect($failed->refresh()->ocr_status)->toBe('pending')->and($failed->ocr_failures)->toBe(0)->and((bool) $failed->ocr_rescan)->toBeTrue();

    // A new upload goes first; the second engine (pass 1 only) leaves re-reads alone.
    $new = ($this->make)('new.jpg', 'pending');
    $this->actingAs($this->admin->user)->getJson('/ocr/next')->assertOk()->assertJson(['id' => $new->id, 'pass' => 1]);
    $this->getJson('/ocr/next?only=1&skip[]='.$new->id)->assertNoContent();
    $this->getJson('/ocr/next?skip[]='.$new->id)->assertOk()->assertJson(['id' => $failed->id, 'pass' => 1]);
    // A read document is read again straight in full; its new text replaces the old one.
    $this->getJson('/ocr/next?skip[]='.$new->id.'&skip[]='.$failed->id)->assertOk()->assertJson(['id' => $read->id, 'pass' => 3]);
    $this->post('/ocr/'.$read->id, ['text' => 'neu', 'pass' => 3])->assertNoContent();
    expect($read->refresh()->ocr_text)->toBe('neu')->and((bool) $read->ocr_rescan)->toBeFalse();
});
