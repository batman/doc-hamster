<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Ocr\OcrLog;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use App\Infrastructure\Environment\LocalOcr;
use App\Infrastructure\Environment\NativeSystemProbe;
use App\Infrastructure\Environment\SystemProbe;
use App\Jobs\Local\RecognizeText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeSystemProbe;

/*
| The server read (points 18/69, A106-A108, A113/A114): which file the app-owned engine reads and how, what ends
| in the document and in the OCR log. Tesseract and the Xpdf tools are small stand-in scripts in a temporary
| storage folder, so nothing is downloaded and the result does not depend on the machine:
|   tesseract  prints the content of the "image"; a file starting with "kaputt" fails like an unreadable image
|   pdftotext  prints the content of the "PDF" (pages end with a form feed); "ohne-ebene…" fails
|   pdftopng   writes one page image "Scan Seite N" while N is within the form feeds of the file
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    // No Tesseract on the PATH, whatever the machine has: only the app-owned engine below counts.
    $probe = new FakeSystemProbe;
    $probe->executables = [];
    $this->app->instance(SystemProbe::class, $probe);

    $this->root = sys_get_temp_dir().'/dh-ocr-'.bin2hex(random_bytes(6));
    $this->app->useStoragePath($this->root);
    Storage::fake('local');
    $standIn = function (string $name, string $script): void {
        $path = $this->root.'/app/ocr/'.$name;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "#!/bin/sh\n".$script."\n");
        chmod($path, 0755);
    };
    $standIn('squashfs-root/AppRun', <<<'SH'
        case "$(head -c 6 "$3")" in
            kaputt) echo 'Error in pixReadStream: Unknown format' >&2; echo 'Image file cannot be read' >&2; exit 1 ;;
        esac
        cat "$3"
        SH);
    $standIn('xpdf/pdftotext', <<<'SH'
        case "$(head -c 10 "$3")" in
            ohne-ebene) echo 'Syntax Error: no text' >&2; exit 1 ;;
        esac
        cat "$3"
        SH);
    $standIn('xpdf/pdftopng', <<<'SH'
        echo "$2" >> "$(dirname "$0")/pdftopng.calls"
        pages=$(tr -cd '\f' < "$8" | wc -c)
        [ "$2" -le "$pages" ] || exit 0
        printf 'Scan Seite %s' "$2" > "$9-$(printf '%06d' "$2").png"
        SH);
    Cache::forever(LocalOcr::STATUS_KEY, ['state' => 'ok', 'reason' => '', 'pdf' => 'ok']);

    $this->admin = Membership::factory()->owner()->create();
    $this->make = function (string $title, ?string $mime, string $content = '', ?Membership $by = null): Document {
        $by ??= $this->admin;
        $document = Document::query()->withoutGlobalScope(OrgScope::class)
            ->create(['org_id' => $by->org_id, 'uploaded_by' => $by->user_id, 'title' => $title]);
        if ($mime !== null) {
            Storage::disk('local')->put('x/'.$title, $content);
            DocumentFile::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => $document->org_id, 'document_id' => $document->id, 'path' => 'x/'.$title, 'mime' => $mime, 'size' => strlen($content), 'sha256' => str_repeat('0', 64)]);
        }

        return $document;
    };
    $this->read = function (Document $document): Document {
        (new RecognizeText($document->id))->handle(app(LocalOcr::class));

        return $document->refresh();
    };
    $this->log = fn (Document $document): OcrLog => OcrLog::query()->withoutGlobalScope(OrgScope::class)->where('document_id', $document->id)->sole();
    $this->pagesAsked = fn (): array => is_file($this->root.'/app/ocr/xpdf/pdftopng.calls')
        ? array_map('intval', file($this->root.'/app/ocr/xpdf/pdftopng.calls', FILE_IGNORE_NEW_LINES) ?: []) : [];
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('reads an image with the app-owned engine and logs the file details', function (): void {
    $document = ($this->read)(($this->make)('bon.jpg', 'image/jpeg', "  Baumarkt 42,00 EUR\n"));

    expect($document)->ocr_text->toBe('Baumarkt 42,00 EUR')->ocr_status->toBe('done')->ocr_pass->toBe(3);
    expect(($this->log)($document))
        ->source->toBe('app')->event->toBe('recognized')->chars->toBe(18)->pass->toBe(3)
        ->file_type->toBe('JPEG')->size->toBe(21)->pages->toBe(1);
});

it('marks an unreadable image failed and logs the last line of the program', function (): void {
    $document = ($this->read)(($this->make)('kaputt.png', 'image/png', 'kaputt'));

    expect($document)->ocr_text->toBeNull()->ocr_status->toBe('failed')->ocr_pass->toBeNull();
    expect(($this->log)($document))->event->toBe('failed')->message->toBe('tesseract: Image file cannot be read')->chars->toBeNull();
});

it('fails with a plain reason when the file is gone from the disk', function (): void {
    $document = ($this->make)('weg.jpg', 'image/jpeg', 'x');
    Storage::disk('local')->delete('x/weg.jpg');

    expect(($this->read)($document))->ocr_status->toBe('failed');
    expect(($this->log)($document))->event->toBe('failed')->message->toBe('Datei fehlt');
});

it('takes the text layer of a PDF as it is when every page has one (A114)', function (): void {
    $document = ($this->read)(($this->make)('rechnung.pdf', 'application/pdf', "Rechnung 4711 vom 01.02.2026, 119,00 EUR\fZahlbar bis 15.02.2026 ohne Abzug\f"));

    expect($document)->ocr_text->toBe("Rechnung 4711 vom 01.02.2026, 119,00 EUR\n\nZahlbar bis 15.02.2026 ohne Abzug")->ocr_status->toBe('done')->ocr_pass->toBe(3);
    expect(($this->log)($document))->event->toBe('text_layer')->pages->toBe(2)->file_type->toBe('PDF');
    // No page was turned into an image.
    expect(($this->pagesAsked)())->toBe([]);
});

it('reads only the pages without text as images and removes them afterwards (A113)', function (): void {
    $document = ($this->read)(($this->make)('gemischt.pdf', 'application/pdf', "Rechnung 4711 vom 01.02.2026, 119,00 EUR\f\f"));

    expect($document)->ocr_text->toBe("Rechnung 4711 vom 01.02.2026, 119,00 EUR\n\nScan Seite 2")->ocr_status->toBe('done');
    expect(($this->log)($document))->event->toBe('recognized')->pages->toBe(2)->source->toBe('app');
    expect(($this->pagesAsked)())->toBe([2])
        ->and(File::directories($this->root.'/app/ocr/tmp'))->toBe([]);
});

it('reads page by page until the PDF ends when the text layer cannot be read', function (): void {
    $document = ($this->read)(($this->make)('scan.pdf', 'application/pdf', "ohne-ebene\f\f"));

    expect($document)->ocr_text->toBe("Scan Seite 1\n\nScan Seite 2")->ocr_status->toBe('done');
    expect(($this->log)($document))->event->toBe('recognized')->pages->toBe(2);
    // Page 3 gave no image: that is how the end is found.
    expect(($this->pagesAsked)())->toBe([1, 2, 3]);
});

it('leaves a file to the browser, or says once why nobody can read it', function (?string $mime, array $status, string $expected, ?string $reason): void {
    Cache::forever(LocalOcr::STATUS_KEY, $status);
    $document = ($this->make)('datei', $mime, 'x');

    expect(($this->read)($document))->ocr_status->toBe($expected);
    ($this->read)($document);
    expect(OcrLog::query()->withoutGlobalScope(OrgScope::class)->where('document_id', $document->id)->pluck('message')->all())
        ->toBe($reason === null ? [] : [$reason]);
})->with([
    'PDF, engine without converter: waits for the browser' => ['application/pdf', ['state' => 'ok'], 'pending', null],
    'image, no engine on the server: waits for the browser' => ['image/jpeg', [], 'pending', null],
    'HEIC with an engine' => ['image/heic', ['state' => 'ok', 'pdf' => 'ok'], 'unavailable', 'HEIC kann Tesseract nicht lesen'],
    'HEIC without an engine' => ['image/heif', [], 'unavailable', 'HEIC im Browser nicht lesbar'],
    'a type nobody reads' => ['application/zip', ['state' => 'ok', 'pdf' => 'ok'], 'unavailable', 'Dateityp ZIP wird nicht erkannt'],
    'no file at all' => [null, ['state' => 'ok', 'pdf' => 'ok'], 'unavailable', 'Keine Datei'],
]);

it('gives the browser routes only documents the person may edit or uploaded', function (): void {
    Cache::forever(LocalOcr::STATUS_KEY, []);
    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->admin->org_id]);
    $foreign = ($this->make)('chef.jpg', 'image/jpeg', 'geheim');
    $own = ($this->make)('eigener.jpg', 'image/jpeg', 'meins', $clerk);
    $file = static fn (Document $d): string => DocumentFile::query()->withoutGlobalScope(OrgScope::class)->where('document_id', $d->id)->sole()->id;

    $this->actingAs($clerk->user)->get('/ocr/image/'.$file($foreign))->assertNotFound();
    $this->post('/ocr/'.$foreign->id, ['text' => 'überschrieben', 'pass' => 3])->assertNotFound();
    $this->post('/ocr/'.$foreign->id.'/progress', ['percent' => 50])->assertNotFound();
    expect($foreign->refresh())->ocr_text->toBeNull()->ocr_status->toBeNull();

    $this->get('/ocr/image/'.$file($own))->assertOk();
    $this->getJson('/ocr/next')->assertOk()->assertJson(['id' => $own->id]);
    $this->getJson('/ocr/next?skip[]='.$own->id)->assertNoContent();
    $this->post('/ocr/'.$own->id, ['text' => 'meins', 'pass' => 1])->assertNoContent();
});

it('works off one waiting job per tick without cron and none while cron runs (A69/A74)', function (): void {
    config(['queue.default' => 'database']);
    $first = ($this->make)('eins.jpg', 'image/jpeg', 'eins');
    $second = ($this->make)('zwei.jpg', 'image/jpeg', 'zwei');
    RecognizeText::dispatch($first->id);
    RecognizeText::dispatch($second->id);

    Cache::put(NativeSystemProbe::HEARTBEAT_CACHE_KEY, time(), 600);
    $this->actingAs($this->admin->user)->postJson('/queue/tick')->assertOk()->assertExactJson(['cron' => true, 'waiting' => 0]);
    expect($first->refresh()->ocr_status)->toBeNull();

    Cache::forget(NativeSystemProbe::HEARTBEAT_CACHE_KEY);
    $this->postJson('/queue/tick')->assertOk()->assertExactJson(['cron' => false, 'waiting' => 1]);
    expect([$first->refresh()->ocr_text, $second->refresh()->ocr_text])->toBe(['eins', null]);
    $this->postJson('/queue/tick')->assertOk()->assertExactJson(['cron' => false, 'waiting' => 0]);
    expect($second->refresh()->ocr_text)->toBe('zwei');
});
