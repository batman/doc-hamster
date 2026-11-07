<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentFile;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use App\Domain\Scanning\ScanRequest;
use App\Jobs\Net\IppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| PHONE (issue #37): "Auf dem Drucker drucken" sends a stored receipt straight
| to the network printer (IPP), or through the Scan Station at home.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->owner = Membership::factory()->owner()->create();
    $this->actingAs($this->owner->user)->post('/documents', ['file' => UploadedFile::fake()->image('bon.jpg')]);
    $this->file = DocumentFile::withoutGlobalScope(OrgScope::class)->firstOrFail();
});

/** An IPP answer: status, then a printer group with the formats it takes. */
function ippAnswer(int $status, array $formats = []): string
{
    $out = pack('CCnN', 2, 0, $status, 1).chr(0x01)
        .chr(0x47).pack('n', 18).'attributes-charset'.pack('n', 5).'utf-8'
        .chr(0x04);
    foreach ($formats as $i => $format) {
        $name = $i === 0 ? 'document-format-supported' : '';
        $out .= chr(0x49).pack('n', strlen($name)).$name.pack('n', strlen($format)).$format;
    }

    return $out.chr(0x03);
}

/** Links a Scan Station like the program does and returns its token. */
function printStation(object $test, Membership $owner): string
{
    $test->actingAs($owner->user)->post('/scanner/stations', ['name' => 'Raspberry']);
    $answer = $test->postJson('/api/station/pair', ['code' => (string) session('station_code'), 'name' => 'Raspberry'])->assertCreated();

    return (string) $answer->json('token');
}

function connectDirectPrinter(Membership $owner): void
{
    $org = Organization::query()->findOrFail($owner->org_id);
    $org->forceFill(['settings_json' => ['scanner' => ['url' => 'http://192.168.0.20']]])->save();
}

it('encodes and reads IPP messages', function (): void {
    $request = IppClient::encode(0x0002, 'ipp://192.168.0.20:631/ipp/print', [[0x49, 'document-format', 'image/jpeg']]);
    expect(substr($request, 0, 4))->toBe(pack('CCn', 2, 0, 2))
        ->and($request)->toContain('printer-uri')->toContain('image/jpeg')
        ->and(ord($request[strlen($request) - 1]))->toBe(0x03);

    $answer = IppClient::decode(ippAnswer(0, ['application/pdf', 'image/jpeg']));
    expect($answer['status'])->toBe(0)
        ->and($answer['attributes']['document-format-supported'])->toBe(['application/pdf', 'image/jpeg']);
});

it('prints straight on the printer in the home network', function (): void {
    connectDirectPrinter($this->owner);
    Http::fake(['http://192.168.0.20:631/ipp/print' => Http::sequence()
        ->push(ippAnswer(0, ['image/jpeg', 'application/pdf']), 200, ['Content-Type' => 'application/ipp'])
        ->push(ippAnswer(0), 200, ['Content-Type' => 'application/ipp'])]);

    $this->get('/documents/'.$this->file->document_id)->assertOk()->assertSee(route('documents.print', $this->file->id));
    $this->from('/documents')->post('/files/'.$this->file->id.'/print')->assertRedirect('/documents')->assertSessionHas('status', __('print.sent'));

    Http::assertSentCount(2);
    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->body(), 'document-format') && str_contains($request->body(), 'image/jpeg')
        && $request->header('Content-Type')[0] === 'application/ipp');
});

it('prints straight from the Dokumente list of the phone app and stays there', function (): void {
    $this->get('/app/documents')->assertOk()->assertDontSee(route('documents.print', $this->file->id));

    connectDirectPrinter($this->owner);
    Http::fake(['http://192.168.0.20:631/ipp/print' => Http::sequence()
        ->push(ippAnswer(0, ['image/jpeg']), 200, ['Content-Type' => 'application/ipp'])
        ->push(ippAnswer(0), 200, ['Content-Type' => 'application/ipp'])]);

    $this->get('/app/documents')->assertOk()->assertSee(route('documents.print', $this->file->id));
    $this->post('/files/'.$this->file->id.'/print', ['app_back' => '/app/documents'])
        ->assertRedirect('/app/documents')->assertSessionHas('status', __('print.sent'));
    Http::assertSentCount(2);
});

it('says so when the printer cannot print the file', function (): void {
    connectDirectPrinter($this->owner);
    Http::fake(['http://192.168.0.20:631/ipp/print' => Http::response(ippAnswer(0, ['image/pwg-raster']), 200, ['Content-Type' => 'application/ipp'])]);

    $this->from('/documents')->post('/files/'.$this->file->id.'/print')->assertSessionHasErrors(['print' => __('print.format_unsupported')]);
});

it('sends the print job to the Scan Station when there is no printer address', function (): void {
    $token = printStation($this, $this->owner);
    $this->postJson('/api/station/poll', [], ['Authorization' => 'Bearer '.$token])->assertNoContent();

    $this->actingAs($this->owner->user)->from('/documents')->post('/files/'.$this->file->id.'/print')->assertSessionHas('status', __('print.queued'));
    $job = ScanRequest::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();
    expect($job->kind)->toBe('print')->and($job->file_id)->toBe($this->file->id);

    // The scan page does not mistake it for a scan.
    $this->get('/scan')->assertOk()->assertDontSee('data-scan-wait', false);

    $poll = $this->postJson('/api/station/poll', [], ['Authorization' => 'Bearer '.$token])->assertOk()->assertJson(['kind' => 'print']);
    $path = (string) $poll->json('file');
    expect($path)->toBe('/api/station/requests/'.$job->id.'/file');

    $this->get($path)->assertUnauthorized();
    $bytes = $this->get($path, ['Authorization' => 'Bearer '.$token])->assertOk()->getContent();
    expect($bytes)->toBe(Storage::disk('local')->get($this->file->path));
    $this->postJson('/api/station/requests/'.$job->id.'/pages', [], ['Authorization' => 'Bearer '.$token])->assertNotFound();

    $this->postJson('/api/station/requests/'.$job->id.'/finish', [], ['Authorization' => 'Bearer '.$token])->assertOk()->assertJson(['status' => 'done']);
});

it('records a failed print with a known reason only', function (): void {
    $token = printStation($this, $this->owner);
    $this->postJson('/api/station/poll', [], ['Authorization' => 'Bearer '.$token]);
    $this->actingAs($this->owner->user)->post('/files/'.$this->file->id.'/print');
    $job = ScanRequest::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();
    $this->postJson('/api/station/poll', [], ['Authorization' => 'Bearer '.$token])->assertOk();

    $this->postJson('/api/station/requests/'.$job->id.'/finish', ['error' => 'rm -rf'], ['Authorization' => 'Bearer '.$token])->assertJson(['status' => 'failed']);
    expect($job->fresh()?->error)->toBe('print.failed');
});

it('offers no printer button and refuses printing without a printer', function (): void {
    $this->get('/documents/'.$this->file->document_id)->assertOk()->assertDontSee(route('documents.print', $this->file->id));
    $this->from('/documents')->post('/files/'.$this->file->id.'/print')->assertSessionHasErrors(['print' => __('print.not_set_up')]);
});

it('prints only receipts the person may see', function (): void {
    connectDirectPrinter($this->owner);
    $stranger = Membership::factory()->owner()->create();
    Http::fake();

    $this->actingAs($stranger->user)->post('/files/'.$this->file->id.'/print')->assertNotFound();
    Http::assertNothingSent();
});
