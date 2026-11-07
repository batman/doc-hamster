<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use App\Domain\Scanning\ScanRequest;
use App\Domain\Scanning\ScanSession;
use App\Domain\Scanning\ScanStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
| Issue #77: the Scan Station. A small program at home connects OUT to the
| server, takes scan requests with its own token and sends the pages back.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    Storage::fake('local');
    $this->admin = Membership::factory()->owner()->create();
    $this->orgId = $this->admin->org_id;
});

function stationJpeg(): string
{
    $image = imagecreatetruecolor(40, 60);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

/** Makes a station in the settings, links it with the code like the program does, returns its token. */
function linkStation(object $test, Membership $admin, string $name = 'Raspberry'): string
{
    $test->actingAs($admin->user)->post('/scanner/stations', ['name' => $name])->assertRedirect('/scanner');
    $code = (string) session('station_code');
    $answer = $test->postJson('/api/station/pair', ['code' => $code, 'name' => $name])->assertCreated();

    return (string) $answer->json('token');
}

function asStation(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

function poll(object $test, string $token, array $body = ['printer' => ['name' => 'Muster Scan 3000', 'feeder' => true]]): TestResponse
{
    return $test->postJson('/api/station/poll', $body, asStation($token));
}

function sendPage(object $test, string $token, string $id, string $bytes): TestResponse
{
    return $test->call('POST', '/api/station/requests/'.$id.'/pages', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'image/jpeg', 'HTTP_ACCEPT' => 'application/json'], $bytes);
}

function waitingRequest(string $orgId): ScanRequest
{
    return ScanRequest::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)->orderByDesc('id')->firstOrFail();
}

it('shows the one-time code once and stores only its hash', function (): void {
    $this->actingAs($this->admin->user)->post('/scanner/stations', ['name' => 'Raspberry'])->assertRedirect('/scanner');
    $code = (string) session('station_code');

    expect($code)->toHaveLength(40);
    $this->get('/scanner')->assertOk()->assertSee($code)->assertSee('Raspberry ist angelegt');
    $this->get('/scanner')->assertDontSee($code);
    $row = ScanStation::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();
    expect($row->pairing_hash)->toBe(hash('sha256', $code))->and($row->token_hash)->toBeNull();
});

it('trades the code for a key exactly once, and never accepts a wrong or expired one', function (): void {
    $this->actingAs($this->admin->user)->post('/scanner/stations', ['name' => 'Pi']);
    $code = (string) session('station_code');

    $this->postJson('/api/station/pair', ['code' => 'not-the-code', 'name' => 'x'])->assertNotFound();
    $first = $this->postJson('/api/station/pair', ['code' => $code, 'name' => 'Pi im Flur'])->assertCreated();
    $this->postJson('/api/station/pair', ['code' => $code, 'name' => 'Pi'])->assertNotFound();

    $row = ScanStation::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();
    expect($first->json('token'))->toHaveLength(48)
        ->and($row->token_hash)->toBe(hash('sha256', (string) $first->json('token')))
        ->and($row->pairing_hash)->toBeNull()
        ->and($row->name)->toBe('Pi im Flur');

    $this->post('/scanner/stations', ['name' => 'Spät']);
    $late = (string) session('station_code');
    $this->travel(25)->hours();
    $this->postJson('/api/station/pair', ['code' => $late, 'name' => 'x'])->assertNotFound();
});

it('answers only to a valid key, and tells it when there is nothing to do', function (): void {
    $token = linkStation($this, $this->admin);

    $this->postJson('/api/station/poll', [])->assertUnauthorized();
    $this->postJson('/api/station/poll', [], asStation('wrong'))->assertUnauthorized();
    poll($this, $token)->assertNoContent();

    $station = ScanStation::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();
    expect($station->printer_name)->toBe('Muster Scan 3000')->and($station->printer_feeder)->toBeTrue()->and($station->isOnline())->toBeTrue();
});

it('carries a whole scan from the click to ONE document through the station', function (): void {
    $token = linkStation($this, $this->admin);
    poll($this, $token)->assertNoContent();

    $this->actingAs($this->admin->user)->post('/scan/page', ['source' => 'feeder', 'color' => 'color', 'dpi' => 200])->assertRedirect('/scan');
    $request = waitingRequest($this->orgId);
    expect($request->status)->toBe('queued');
    $this->get('/scan')->assertOk()->assertSee('Die Scan Station scannt gerade');

    $job = poll($this, $token)->assertOk()->assertJson(['id' => $request->id, 'source' => 'feeder', 'color' => 'color', 'dpi' => 200]);
    poll($this, $token)->assertNoContent();
    expect($request->fresh()->status)->toBe('running');
    $this->actingAs($this->admin->user)->get('/scan')->assertSee('Die Scan Station scannt gerade');

    sendPage($this, $token, $job->json('id'), stationJpeg())->assertCreated()->assertJson(['pages' => 1]);
    sendPage($this, $token, $job->json('id'), stationJpeg())->assertCreated()->assertJson(['pages' => 2]);
    $this->postJson('/api/station/requests/'.$job->json('id').'/finish', [], asStation($token))->assertOk()->assertJson(['status' => 'done']);

    $this->actingAs($this->admin->user)->get('/scan')->assertSee('2 Seiten')->assertDontSee('Die Scan Station scannt gerade');
    $this->post('/scan/finish', ['title' => 'Vom Flur'])->assertRedirect();

    expect(Document::query()->withoutGlobalScope(OrgScope::class)->where('title', 'Vom Flur')->count())->toBe(1)
        ->and((new ScanSession($this->orgId, $this->admin->user_id))->count())->toBe(0);
});

it('tells the person once when the station could not scan', function (): void {
    $token = linkStation($this, $this->admin);
    poll($this, $token);
    $this->actingAs($this->admin->user)->post('/scan/page', ['source' => 'feeder', 'color' => 'color', 'dpi' => 200]);
    $job = poll($this, $token)->assertOk();

    $this->postJson('/api/station/requests/'.$job->json('id').'/finish', ['error' => 'scan.feeder_empty'], asStation($token))->assertOk()->assertJson(['status' => 'failed']);

    $this->actingAs($this->admin->user)->get('/scan')->assertSee('Im Einzugsfach liegt kein Papier.');
    $this->get('/scan')->assertDontSee('Im Einzugsfach liegt kein Papier.');
});

it('turns an unknown error text into the general message and an empty finish into "no page"', function (): void {
    $token = linkStation($this, $this->admin);
    poll($this, $token);
    $this->actingAs($this->admin->user)->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200]);
    $job = poll($this, $token)->assertOk();

    $this->postJson('/api/station/requests/'.$job->json('id').'/finish', ['error' => '<script>alert(1)</script>'], asStation($token))->assertOk();
    expect(waitingRequest($this->orgId)->error)->toBe('scan.failed');

    $this->actingAs($this->admin->user)->get('/scan');
    $this->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200]);
    $second = poll($this, $token)->assertOk();
    $this->postJson('/api/station/requests/'.$second->json('id').'/finish', [], asStation($token))->assertJson(['status' => 'failed']);
    expect(waitingRequest($this->orgId)->error)->toBe('scan.no_page');
});

it('refuses pages that are no JPEG, pages for requests that are not running, and other stations', function (): void {
    $token = linkStation($this, $this->admin);
    $other = linkStation($this, $this->admin, 'Zweite');
    poll($this, $token);
    $this->actingAs($this->admin->user)->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200]);
    $request = waitingRequest($this->orgId);

    sendPage($this, $token, $request->id, stationJpeg())->assertNotFound();
    $job = poll($this, $token)->assertOk();

    sendPage($this, $token, $job->json('id'), 'GIF89a not a jpeg')->assertStatus(422);
    sendPage($this, $token, $job->json('id'), '')->assertStatus(413);
    sendPage($this, $other, $job->json('id'), stationJpeg())->assertNotFound();
    $this->postJson('/api/station/requests/'.$job->json('id').'/finish', [], asStation($other))->assertNotFound();
    expect((new ScanSession($this->orgId, $this->admin->user_id))->count())->toBe(0);

    $this->postJson('/api/station/requests/'.$job->json('id').'/finish', ['error' => 'scan.busy'], asStation($token))->assertOk();
    sendPage($this, $token, $job->json('id'), stationJpeg())->assertNotFound();
});

it('puts the pages with the person who asked, and stops when that person has lost access', function (): void {
    $token = linkStation($this, $this->admin);
    $colleague = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->orgId]);
    poll($this, $token);
    $this->actingAs($colleague->user)->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200])->assertRedirect('/scan');
    $job = poll($this, $token)->assertOk();

    sendPage($this, $token, $job->json('id'), stationJpeg())->assertCreated();
    expect((new ScanSession($this->orgId, $colleague->user_id))->count())->toBe(1)
        ->and((new ScanSession($this->orgId, $this->admin->user_id))->count())->toBe(0);

    $colleague->forceFill(['revoked_at' => now()])->save();
    sendPage($this, $token, $job->json('id'), stationJpeg())->assertForbidden();
});

it('does not queue a scan while the station is silent, and gives up on requests nobody takes', function (): void {
    $token = linkStation($this, $this->admin);
    poll($this, $token);
    $this->actingAs($this->admin->user)->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200]);
    expect(waitingRequest($this->orgId)->status)->toBe('queued');

    $this->travel(3)->minutes();
    $this->get('/scan')->assertSee('meldet sich gerade nicht');
    expect(waitingRequest($this->orgId)->status)->toBe('failed')->and(waitingRequest($this->orgId)->error)->toBe('scan.station_offline');

    $this->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200])->assertSessionHasErrors('scan');
    expect(ScanRequest::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(1);
});

it('asks only once while a scan is running', function (): void {
    $token = linkStation($this, $this->admin);
    poll($this, $token);
    $this->actingAs($this->admin->user)->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200]);
    $this->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200])->assertRedirect('/scan');

    expect(ScanRequest::query()->withoutGlobalScope(OrgScope::class)->count())->toBe(1);
});

it('lists stations, removes one for good, and its key stops working', function (): void {
    $token = linkStation($this, $this->admin);
    poll($this, $token);
    $this->actingAs($this->admin->user)->get('/scanner')->assertSee('Raspberry')->assertSee('Muster Scan 3000')->assertSee('verbunden');
    $station = ScanStation::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();

    $this->post('/scanner/stations/'.$station->id.'/revoke')->assertRedirect('/scanner');

    poll($this, $token)->assertUnauthorized();
    $this->get('/scanner')->assertDontSee('Muster Scan 3000');
    $this->get('/documents')->assertDontSee('href="'.route('scan.index').'"', false);
});

it('keeps stations to the organisation, lets people who may upload link one, and only Admins remove one (A33)', function (): void {
    $token = linkStation($this, $this->admin);
    poll($this, $token);
    $stranger = Membership::factory()->owner()->create();
    $uploader = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $this->orgId]);

    $this->actingAs($stranger->user)->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200])->assertSessionHasErrors('scan');
    $this->get('/documents')->assertDontSee('href="'.route('scan.index').'"', false);
    $this->actingAs($uploader->user)->get('/scanner')->assertOk();
    $this->post('/scanner/stations', ['name' => 'Zweiter Rechner'])->assertRedirect('/scanner');
    $this->get('/scanner/station-download')->assertOk();
    $station = ScanStation::query()->withoutGlobalScope(OrgScope::class)->whereNotNull('token_hash')->firstOrFail();
    $this->post('/scanner/stations/'.$station->id.'/revoke')->assertForbidden();
    $this->post('/scan/page', ['source' => 'platen', 'color' => 'color', 'dpi' => 200])->assertRedirect('/scan');
    $this->get('/documents')->assertSee('href="'.route('scan.index').'"', false);
});

it('lets an Admin download the station program', function (): void {
    $response = $this->actingAs($this->admin->user)->get('/scanner/station-download')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('dochamster-station.php')
        ->and(file_get_contents(base_path('station/dochamster-station.php')))->toContain('DocHamster Scan Station');
});
