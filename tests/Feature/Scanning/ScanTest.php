<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Folder;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| Issue #77: scan from a network printer or scanner (eSCL), one page or a
| feeder stack, always ending as one document in a folder. No real device:
| every answer of the device is faked.
*/
uses(RefreshDatabase::class)->group('db');

const CAPABILITIES = '<?xml version="1.0"?><scan:ScannerCapabilities xmlns:pwg="http://www.pwg.org/schemas/2010/12/sm" xmlns:scan="http://schemas.hp.com/imaging/escl/2011/05/03"><pwg:Version>2.9</pwg:Version><pwg:MakeAndModel>Muster Scan 3000</pwg:MakeAndModel><scan:Platen><scan:PlatenInputCaps/></scan:Platen><scan:Adf><scan:AdfSimplexInputCaps/></scan:Adf></scan:ScannerCapabilities>';

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->orgId = $this->admin->org_id;
});

function scanJpeg(int $width = 40, int $height = 60): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

/** The device behind 10.0.0.5: a job that hands out $pages one by one, then 404. */
function fakeDevice(array $pages, int $jobStatus = 201): void
{
    // A fresh client: stubs of an earlier call must not answer first.
    Http::swap(new Factory);
    $sequence = Http::sequence();
    foreach ($pages as $page) {
        $sequence->push($page, 200, ['Content-Type' => 'image/jpeg']);
    }
    $sequence->push('', 404);

    Http::fake([
        '10.0.0.5/eSCL/ScannerCapabilities' => Http::response(CAPABILITIES, 200),
        '10.0.0.5/eSCL/ScanJobs' => Http::response('', $jobStatus, ['Location' => 'http://10.0.0.5/eSCL/ScanJobs/job-1']),
        '10.0.0.5/eSCL/ScanJobs/job-1/NextDocument' => $sequence,
        '10.0.0.5/eSCL/ScanJobs/job-1' => Http::response('', 200),
    ]);
}

function connectScanner(string $orgId): void
{
    Organization::query()->findOrFail($orgId)->update(['settings_json' => ['scanner' => ['url' => 'http://10.0.0.5', 'name' => 'Muster Scan 3000', 'feeder' => true]]]);
}

function storedScannerUrl(string $orgId): ?string
{
    return Organization::query()->findOrFail($orgId)->settings_json['scanner']['url'] ?? null;
}

it('connects a scanner after it answered as one, and stores its model', function (): void {
    fakeDevice([]);

    $this->actingAs($this->admin->user)->post('/scanner', ['url' => '10.0.0.5'])->assertRedirect('/scanner');

    expect(storedScannerUrl($this->orgId))->toBe('http://10.0.0.5')
        ->and(Organization::query()->findOrFail($this->orgId)->settings_json['scanner']['name'])->toBe('Muster Scan 3000')
        ->and(Organization::query()->findOrFail($this->orgId)->settings_json['scanner']['feeder'])->toBeTrue();
    $this->get('/scanner')->assertOk()->assertSee('Muster Scan 3000');
    $this->get('/documents')->assertSee('Scannen');
});

it('never calls an address outside the private network and does not store it', function (): void {
    Http::fake();

    $this->actingAs($this->admin->user)->post('/scanner', ['url' => '8.8.8.8'])->assertSessionHasErrors('url');
    $this->post('/scanner', ['url' => '169.254.169.254'])->assertSessionHasErrors('url');
    $this->post('/scanner', ['url' => 'http://user:pw@10.0.0.5'])->assertSessionHasErrors('url');

    Http::assertNothingSent();
    expect(storedScannerUrl($this->orgId))->toBeNull();
});

it('does not store a device that is no scanner or does not answer', function (): void {
    Http::fake(['10.0.0.5/*' => Http::response('<html>router</html>', 200)]);
    $this->actingAs($this->admin->user)->post('/scanner', ['url' => '10.0.0.5'])->assertSessionHasErrors('url');

    Http::fake(['10.0.0.5/*' => Http::response('', 404)]);
    $this->post('/scanner', ['url' => '10.0.0.5'])->assertSessionHasErrors('url');

    Http::fake(fn () => throw new ConnectionException('down'));
    $this->post('/scanner', ['url' => '10.0.0.5'])->assertSessionHasErrors('url');
    $this->get('/scanner')->assertDontSee('Verbunden mit');

    expect(storedScannerUrl($this->orgId))->toBeNull();
});

it('removes the scanner when the address is emptied', function (): void {
    connectScanner($this->orgId);

    $this->actingAs($this->admin->user)->post('/scanner', ['url' => ''])->assertRedirect('/scanner');

    expect(storedScannerUrl($this->orgId))->toBeNull();
});

it('scans one page from the glass, shows it, and turns it into a document in a folder', function (): void {
    connectScanner($this->orgId);
    fakeDevice([scanJpeg()]);
    $folder = Folder::withoutEvents(static fn (): Folder => Folder::query()->withoutGlobalScope(OrgScope::class)->create(['org_id' => test()->orgId, 'name' => 'Steuer']));
    $this->actingAs($this->admin->user);

    $this->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200])->assertRedirect('/scan');
    $this->get('/scan')->assertOk()->assertSee('1 Seite')->assertSee('/scan/pages/1', false);
    $this->get('/scan/pages/1')->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    $this->post('/scan/finish', ['title' => 'Mietvertrag', 'folder' => $folder->id])->assertRedirect();

    $document = Document::query()->withoutGlobalScope(OrgScope::class)->where('title', 'Mietvertrag')->firstOrFail();
    $file = DocumentFile::withoutGlobalScope(OrgScope::class)->where('document_id', $document->id)->firstOrFail();
    expect($document->folder_id)->toBe($folder->id)
        ->and($file->mime)->toBe('application/pdf')
        ->and(Storage::disk('local')->get($file->path))->toStartWith('%PDF-1.4');
    $this->get('/scan')->assertDontSee('1 Seite');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'http://10.0.0.5/eSCL/ScanJobs/job-1');
});

it('takes a whole stack from the feeder in one job and several scans together into ONE document', function (): void {
    connectScanner($this->orgId);
    fakeDevice([scanJpeg(), scanJpeg(), scanJpeg()]);
    $this->actingAs($this->admin->user);

    $this->post('/scan/page', ['source' => 'feeder', 'color' => 'gray', 'dpi' => 300])->assertRedirect('/scan');
    $this->get('/scan')->assertSee('3 Seiten');

    fakeDevice([scanJpeg()]);
    $this->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 300])->assertRedirect('/scan');
    $this->get('/scan')->assertSee('4 Seiten');

    $this->post('/scan/pages/2/delete')->assertRedirect('/scan');
    $this->get('/scan')->assertSee('3 Seiten');

    $this->post('/scan/finish', [])->assertRedirect();

    $file = DocumentFile::withoutGlobalScope(OrgScope::class)->firstOrFail();
    expect(Document::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(1)
        ->and(Storage::disk('local')->get($file->path))->toContain('/Count 3')
        ->and(Document::query()->withoutGlobalScope(OrgScope::class)->firstOrFail()->folder_id)->toBe(Folder::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $this->orgId)->where('is_default', true)->firstOrFail()->id)
        ->and(Document::query()->withoutGlobalScope(OrgScope::class)->firstOrFail()->title)->toStartWith('Scan ');
});

it('says what went wrong when the device cannot scan', function (): void {
    connectScanner($this->orgId);
    $this->actingAs($this->admin->user);

    fakeDevice([]);
    $this->post('/scan/page', ['source' => 'feeder', 'color' => 'color', 'dpi' => 200])->assertSessionHasErrors(['scan' => 'Im Einzugsfach liegt kein Papier.']);

    fakeDevice([], 503);
    $this->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200])->assertSessionHasErrors(['scan' => 'Das Gerät ist gerade beschäftigt. Versuche es gleich noch einmal.']);

    Http::fake(fn () => throw new ConnectionException('down'));
    $this->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200])->assertSessionHasErrors('scan');

    $this->post('/scan/finish', [])->assertSessionHasErrors(['scan' => 'Es gibt noch keine gescannte Seite.']);
    expect(Document::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(0);
});

it('refuses odd settings and a stored address that is not private any more', function (): void {
    connectScanner($this->orgId);
    Http::fake();
    $this->actingAs($this->admin->user);

    $this->post('/scan/page', ['source' => 'tray', 'color' => 'color', 'dpi' => 200])->assertSessionHasErrors('source');
    $this->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 9999])->assertSessionHasErrors('dpi');

    Organization::query()->findOrFail($this->orgId)->update(['settings_json' => ['scanner' => ['url' => 'http://127.0.0.1', 'name' => 'x', 'feeder' => false]]]);
    $this->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200])->assertSessionHasErrors('scan');
    $this->get('/scan')->assertSee('noch kein Drucker oder Scanner verbunden');

    Http::assertNothingSent();
});

it('keeps scanned pages private to the person and the organisation', function (): void {
    connectScanner($this->orgId);
    fakeDevice([scanJpeg()]);
    $this->actingAs($this->admin->user)->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200]);

    $colleague = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->orgId]);
    $stranger = Membership::factory()->owner()->create();

    $this->actingAs($colleague->user)->get('/scan/pages/1')->assertNotFound();
    $this->actingAs($stranger->user)->get('/scan/pages/1')->assertNotFound();
    $this->actingAs($this->admin->user)->get('/scan/pages/1')->assertOk();
});

it('lets only people who may upload scan and connect a device, and only Admins remove it (A33)', function (): void {
    connectScanner($this->orgId);
    $viewer = Membership::factory()->accountant()->create(['org_id' => $this->orgId]);
    $uploader = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->orgId]);

    $this->actingAs($viewer->user)->get('/scan')->assertForbidden();
    $this->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200])->assertForbidden();
    $this->actingAs($uploader->user)->get('/scan')->assertOk();
    $this->get('/scanner')->assertOk();
    $this->post('/scanner', ['url' => ''])->assertForbidden();
    $this->actingAs($viewer->user)->get('/scanner')->assertForbidden();
});

it('shows the scan entry only when a scanner is connected', function (): void {
    $this->actingAs($this->admin->user)->get('/documents')->assertDontSee('href="'.route('scan.index').'"', false);

    connectScanner($this->orgId);

    $this->get('/documents')->assertSee('href="'.route('scan.index').'"', false);
});
