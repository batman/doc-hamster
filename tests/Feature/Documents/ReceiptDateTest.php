<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\ReceiptDate;
use App\Domain\Log\LogEntry;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Properties\PropertyValues;
use App\Jobs\Local\BackfillReceiptDates;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ReceiptFiles;

/*
| Point 214, A381: the Belegdatum follows the invoice date (confirmed beats recognised, implausible ones stay a
| suggestion), else the photo's EXIF date, the PDF's creation date, the upload day; the one-off backfill.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-14 10:00:00');
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->actingAs($this->admin->user);
    $this->doc = function (?string $mime = null, ?string $content = null): Document {
        $document = Document::query()->withoutGlobalScope(OrgScope::class)
            ->create(['org_id' => $this->admin->org_id, 'uploaded_by' => $this->admin->user_id, 'title' => 'beleg']);
        if ($mime !== null) {
            $path = $this->admin->org_id.'/'.$document->id.'.bin';
            Storage::disk('local')->put($path, (string) $content);
            DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create([
                'org_id' => $this->admin->org_id, 'document_id' => $document->id, 'path' => $path, 'mime' => $mime,
                'size' => strlen((string) $content), 'sha256' => hash('sha256', (string) $content),
            ]);
        }

        return $document;
    };
    $this->fresh = fn (Document $d): Document => Document::query()->withoutGlobalScopes()->findOrFail($d->id);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('follows the invoice date: recognised, then confirmed, and back to the fallback when cleared', function (): void {
    $doc = ($this->doc)();
    $values = app(PropertyValues::class);

    $values->set($doc, 'invoice_date', '03.04.2026', 'suggested');
    expect(($this->fresh)($doc)->doc_date?->toDateString())->toBe('2026-04-03')->and(($this->fresh)($doc)->doc_date_source)->toBe('recognised');

    $values->set($doc, 'invoice_date', '05.04.2026');
    $values->set($doc, 'invoice_date', '07.04.2026', 'suggested');
    expect(($this->fresh)($doc)->doc_date?->toDateString())->toBe('2026-04-05')->and(($this->fresh)($doc)->doc_date_source)->toBe('confirmed');

    $property = $values->get($doc, 'invoice_date');
    $this->postJson("/documents/{$doc->id}/properties", ['property' => 'invoice_date', 'value' => ''])->assertOk();
    expect(($this->fresh)($doc)->doc_date_source)->toBe('upload')->and(($this->fresh)($doc)->doc_date?->toDateString())->toBe('2026-11-14')
        ->and($property)->not->toBeNull();
});

it('keeps an implausible recognised date as a suggestion with a hint (A381)', function (): void {
    $doc = ($this->doc)();
    app(PropertyValues::class)->set($doc, 'invoice_date', '01.02.2041', 'suggested');

    $fresh = ($this->fresh)($doc);
    expect($fresh->doc_date_source)->toBe('upload')
        ->and(app(ReceiptDate::class)->doubtful($fresh)?->toDateString())->toBe('2041-02-01');

    // Confirming it makes it the Belegdatum after all.
    $value = app(PropertyValues::class)->get($doc, 'invoice_date');
    $this->postJson("/documents/{$doc->id}/properties/{$value?->property_id}/confirm")->assertOk();
    expect(($this->fresh)($doc)->doc_date?->toDateString())->toBe('2041-02-01')->and(($this->fresh)($doc)->doc_date_source)->toBe('confirmed');
});

it('falls back to the EXIF date, the PDF date and the upload day in that order', function (): void {
    $dates = app(ReceiptDate::class);
    $png = ($this->doc)('image/png', ReceiptFiles::png());
    $pdf = ($this->doc)('application/pdf', ReceiptFiles::pdfCreated('20250102'));
    $oldPdf = ($this->doc)('application/pdf', ReceiptFiles::pdfCreated('19990102'));

    expect($dates->resolve($png))->toBe(['2026-11-14', 'upload'])
        ->and($dates->resolve($pdf))->toBe(['2025-01-02', 'pdf'])
        ->and($dates->resolve($oldPdf))->toBe(['2026-11-14', 'upload']);
    if (function_exists('exif_read_data')) {
        $jpeg = ($this->doc)('image/jpeg', ReceiptFiles::jpegWithExif('2025:06:30 08:15:00'));
        expect($dates->resolve($jpeg))->toBe(['2025-06-30', 'exif']);
        // A recognised invoice date beats the photo's own date.
        app(PropertyValues::class)->set($jpeg, 'invoice_date', '29.06.2025', 'suggested');
        expect($dates->resolve($jpeg))->toBe(['2025-06-29', 'recognised']);
    }
});

it('backfills every document once and logs the count per organisation', function (): void {
    $plain = ($this->doc)();
    $pdf = ($this->doc)('application/pdf', ReceiptFiles::pdfCreated('20250102'));
    $dated = ($this->doc)();
    app(PropertyValues::class)->set($dated, 'invoice_date', '10.10.2026');
    // As before the update: nothing worked out yet.
    DB::table('documents')->update(['doc_date' => null, 'doc_date_source' => null]);

    $counts = app()->call([new BackfillReceiptDates, 'handle']);

    expect($counts)->toBe([$this->admin->org_id => 3])
        ->and(($this->fresh)($plain)->doc_date_source)->toBe('upload')
        ->and(($this->fresh)($pdf)->doc_date_source)->toBe('pdf')
        ->and(($this->fresh)($dated)->doc_date?->toDateString())->toBe('2026-10-10')
        ->and(LogEntry::query()->withoutGlobalScopes()->where('event', 'document.dates_set')->value('message'))->toBe('Belegdatum für 3 Dokumente gesetzt')
        ->and(app()->call([new BackfillReceiptDates, 'handle']))->toBe([]);
});

it('finds documents without a Belegdatum of their own', function (): void {
    $plain = ($this->doc)();
    $dated = ($this->doc)();
    app(ReceiptDate::class)->sync($plain);
    app(PropertyValues::class)->set($dated, 'invoice_date', '10.10.2026');

    $this->get('/documents?nodate=1&all=1')->assertOk()->assertSee($plain->id)->assertDontSee($dated->id);
});
