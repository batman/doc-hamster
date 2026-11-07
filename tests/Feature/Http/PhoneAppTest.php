<?php

declare(strict_types=1);

use App\Domain\Auth\Device;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\OrgScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
| The phone app (PHONE, issue #37): /app opens the camera at once, the bottom
| bar has only Belege and Dokumente, Dokumente holds folders, documents and
| scanning. Forms use the website's routes and come back via app_back.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->owner = Membership::factory()->owner()->create();
});

function appFolder(string $name, ?string $parent = null): Folder
{
    return Folder::withoutGlobalScope(OrgScope::class)->where('name', $name)->when($parent !== null, fn ($q) => $q->where('parent_id', $parent))->firstOrFail();
}

it('opens the camera at once with only Belege and Dokumente in the bar', function (): void {
    $this->actingAs($this->owner->user)->get('/app')->assertOk()
        ->assertSee('data-live-camera', false)
        ->assertSee('data-shutter', false)
        ->assertSee('data-queue-upload', false)
        ->assertSee('capture="environment"', false)
        ->assertSee(__('app.bar.receipts'))
        ->assertSee(__('app.bar.documents_app'))
        ->assertDontSee('nav-toggle', false)
        ->assertDontSee(__('phone.home_hint'));
});

it('keeps the website without the phone bar', function (): void {
    $this->actingAs($this->owner->user)->get('/documents')->assertOk()
        ->assertSee('rel="manifest"', false)
        ->assertDontSee('phone-bar', false)
        ->assertDontSee('data-queue-upload', false);
});

it('sends a photo from the camera into Belege and back to the camera', function (): void {
    $this->actingAs($this->owner->user)
        ->post('/documents', ['files' => [UploadedFile::fake()->image('beleg.jpg')], 'app_back' => '/app'])
        ->assertRedirect('/app');

    expect(Document::withoutGlobalScope(OrgScope::class)->count())->toBe(1);
});

it('creates, renames and deletes folders from Dokumente', function (): void {
    $this->actingAs($this->owner->user);

    $this->post('/folders', ['name' => 'Steuer', 'parent_id' => '', 'app_back' => '/app/documents'])->assertRedirect('/app/documents');
    $steuer = appFolder('Steuer');
    $this->post('/folders', ['name' => '2026', 'parent_id' => $steuer->id, 'app_back' => '/app/documents?folder='.$steuer->id])
        ->assertRedirect('/app/documents?folder='.$steuer->id);

    $this->get('/app/documents')->assertOk()->assertSee('Steuer')->assertDontSee('>2026<', false);
    $this->get('/app/documents?folder='.$steuer->id)->assertOk()->assertSee('2026');

    $this->post('/folders/'.$steuer->id, ['name' => 'Steuern', 'parent_id' => '', 'bank_matching' => '1', 'app_back' => '/app/documents'])
        ->assertRedirect('/app/documents');
    expect($steuer->fresh()?->name)->toBe('Steuern')->and($steuer->fresh()?->bank_matching)->toBeTrue();

    $child = appFolder('2026');
    $this->post('/folders/'.$child->id.'/delete', ['app_back' => '/app/documents?folder='.$steuer->id])
        ->assertRedirect('/app/documents?folder='.$steuer->id);
    expect(Folder::withoutGlobalScope(OrgScope::class)->whereKey($child->id)->exists())->toBeFalse();
});

it('shows a document with download, rename and delete, and returns to the list', function (): void {
    $this->actingAs($this->owner->user)->post('/documents', ['file' => UploadedFile::fake()->image('bon.jpg')]);
    $document = Document::withoutGlobalScope(OrgScope::class)->with('files')->firstOrFail();

    $this->get('/app/documents/'.$document->id)->assertOk()
        ->assertSee(route('documents.download', $document->files->first()->id), false)
        ->assertSee('data-share', false)
        ->assertSee('name="app_back"', false);

    $this->post('/documents/'.$document->id, ['title' => 'Tankquittung', 'note' => '', 'folder' => '', 'app_back' => '/app/documents/'.$document->id])
        ->assertRedirect('/app/documents/'.$document->id);
    expect($document->fresh()?->title)->toBe('Tankquittung');

    $this->post('/documents/'.$document->id.'/delete', ['app_back' => '/app/documents'])->assertRedirect('/app/documents');
    expect(Document::withoutGlobalScope(OrgScope::class)->count())->toBe(0);
});

it('never sends anyone outside the app with app_back', function (string $back): void {
    $this->actingAs($this->owner->user)
        ->post('/folders', ['name' => 'Ordner '.md5($back), 'parent_id' => '', 'app_back' => $back])
        ->assertRedirectContains('/folders');
})->with(['https://evil.example/app', '//evil.example/app', '/settings', '/app/../settings', '/appx', '/app/documents?x=<b>']);

it('asks to connect a scanner first, only admins may', function (): void {
    $this->actingAs($this->owner->user)->get('/app/scan')->assertRedirect('/app/scanner');
    $this->get('/app/scanner')->assertOk()->assertSee(__('app.scanner.search'))->assertDontSee(__('app.scanner.ask_admin'));
    $this->get('/app/documents')->assertSee(__('app.scan.connect'));

    $member = Membership::factory()->create(['org_id' => $this->owner->org_id]);
    $this->actingAs($member->user)->get('/app/scanner')->assertOk()->assertSee(__('app.scanner.ask_admin'))->assertDontSee(__('app.scanner.search'));
    $this->get('/app/documents')->assertDontSee(__('app.scan.connect'))->assertDontSee(__('app.scan.title'));
    $this->post('/app/scanner/search')->assertForbidden();
});

it('searches the network for scanners for an admin', function (): void {
    Http::fake(['*' => Http::response('', 404)]);

    $this->actingAs($this->owner->user)->post('/app/scanner/search')->assertRedirect('/app/scanner');
    expect(session('scanners_found'))->toBeArray();
});

it('scans one page or several once a scanner is connected', function (): void {
    Organization::query()->findOrFail($this->owner->org_id)->forceFill(['settings_json' => ['scanner' => ['url' => 'http://192.168.0.20', 'name' => 'Testdrucker']]])->save();

    $this->actingAs($this->owner->user)->get('/app/documents')->assertSee(__('app.scan.title'));
    $this->get('/app/scan')->assertOk()
        ->assertSee(__('app.scan.one'))
        ->assertSee(__('app.scan.many'))
        ->assertSee('Testdrucker');
});

it('does not count a stored public scanner address as connected', function (): void {
    Organization::query()->findOrFail($this->owner->org_id)->forceFill(['settings_json' => ['scanner' => ['url' => 'http://203.0.113.5']]])->save();

    $this->actingAs($this->owner->user)->get('/app/scan')->assertRedirect('/app/scanner');
});

it('names a new phone by itself and lets the person rename it', function (): void {
    $this->actingAs($this->owner->user)->post('/phone/code');
    $code = (string) session('pairing_code');
    Auth::logout();
    $this->flushSession();

    $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)')
        ->post('/connect', ['code' => $code])->assertRedirect('/app');
    $device = Device::query()->firstOrFail();
    expect($device->name)->toBe('iPhone');

    $this->actingAs($this->owner->user)->post('/phone/devices/'.$device->id.'/rename', ['name' => 'Diensthandy'])->assertRedirect('/phone');
    expect($device->fresh()?->name)->toBe('Diensthandy');
});

it('offers connecting with the phone on the login page', function (): void {
    $this->get('/login')->assertOk()
        ->assertSee(__('phone.login_button'))
        ->assertSee(route('phone.connect'), false);
});
