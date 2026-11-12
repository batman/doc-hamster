<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\SystemProbe;
use App\Jobs\Local\RecognizeText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSystemProbe;

/*
| Point 58/A63 on the server: a read that fails keeps the text a document already has (found by an earlier
| read or corrected by hand); only a document without text is marked failed.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    // A server with the OCR add-on, whatever is installed on the machine running the tests. The file of every
    // document here is missing on the disk, so each read fails before any program is started.
    $this->app->instance(SystemProbe::class, new FakeSystemProbe);
    $this->admin = Membership::factory()->owner()->create();
    $this->make = function (string $title, ?string $text): Document {
        $document = Document::query()->withoutGlobalScope(OrgScope::class)
            ->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => $title]);
        $document->forceFill(['ocr_status' => 'done', 'ocr_pass' => 2, 'ocr_text' => $text])->save();
        DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $document->org_id, 'document_id' => $document->id, 'path' => 'fehlt/'.$title, 'mime' => 'image/jpeg', 'size' => 4, 'sha256' => str_repeat('0', 64)]);

        return $document;
    };
});

it('keeps the earlier text when "Neu erkennen" fails on the server', function (): void {
    $document = ($this->make)('bon.jpg', 'Baumarkt 42,00 EUR');

    // The queue is sync in tests: the read runs inside this request.
    $this->actingAs($this->admin->user)->post('/documents/'.$document->id.'/ocr')->assertRedirect();

    expect($document->refresh())
        ->ocr_text->toBe('Baumarkt 42,00 EUR')
        ->ocr_status->toBe('done')
        ->ocr_pass->toBe(3);
    // The failure is not hidden: it is in the log with its reason.
    expect(OcrLog::query()->withoutGlobalScope(OrgScope::class)->where('document_id', $document->id)->firstOrFail())
        ->event->toBe('failed')->message->toBe('Datei fehlt')->source->toBe('system');
    $this->get('/documents?q=Baumarkt')->assertOk()->assertSee('bon.jpg');
});

it('still marks a document without text failed', function (?string $text): void {
    $document = ($this->make)('leer.jpg', $text);
    $document->forceFill(['ocr_status' => 'pending', 'ocr_pass' => null])->save();

    (new RecognizeText($document->id))->handle(app(LocalOcr::class));

    expect($document->refresh())->ocr_text->toBeNull()->ocr_status->toBe('failed')->ocr_pass->toBeNull();
})->with([
    'never read' => [null],
    'read, nothing found' => [''],
]);
