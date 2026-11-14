<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Printing\Printer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
| Printer "Hamster": the download links one computer, prints land in the
| Posteingang (point 153) as ordinary documents with the source "Drucker".
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(fn () => Storage::fake('local'));

/** Downloads the installer and links a computer with its code; returns the token. */
function pairPrinter(Membership $member): string
{
    $download = test()->actingAs($member->user)->post('/settings/eingang/printer/setup');
    $download->assertOk()->assertDownload('Hamster-Drucker-einrichten.cmd');
    preg_match("/\\\$PairingCode = '([A-Za-z0-9]+)'/", (string) $download->getContent(), $code);

    return (string) test()->postJson('/api/printer/pair', ['code' => $code[1], 'name' => 'BUERO-PC'])->assertCreated()->json('token');
}

function printOn(string $token, string $title = 'Rechnung.docx'): TestResponse
{
    return test()->withToken($token)->post('/api/printer/upload', [
        'file' => UploadedFile::fake()->createWithContent('druck.pdf', "%PDF-1.4\n%synthetic\n"),
        'title' => $title,
    ], ['Accept' => 'application/json']);
}

it('hands out the readable installer with server address and a one-time code', function (): void {
    $owner = Membership::factory()->owner()->create();

    $script = (string) $this->actingAs($owner->user)->post('/settings/eingang/printer/setup')->assertOk()->getContent();

    expect($script)->toStartWith("<# : Hamster-Drucker-einrichten.cmd\r\n")
        ->toContain("\$ServerUrl = '".rtrim((string) config('app.url'), '/')."'")
        ->not->toContain('__PAIRING_CODE__')
        ->and(preg_match("/(?<!\r)\n/", $script))->toBe(0);

    $token = pairPrinter($owner);
    expect($token)->toHaveLength(48);
});

it('takes a code only once', function (): void {
    $owner = Membership::factory()->owner()->create();
    $download = (string) $this->actingAs($owner->user)->post('/settings/eingang/printer/setup')->getContent();
    preg_match("/\\\$PairingCode = '([A-Za-z0-9]+)'/", $download, $code);

    $this->postJson('/api/printer/pair', ['code' => $code[1]])->assertCreated();
    $this->postJson('/api/printer/pair', ['code' => $code[1]])->assertNotFound();
});

it('puts a print into the Posteingang under Drucker', function (): void {
    $owner = Membership::factory()->owner()->create();
    $token = pairPrinter($owner);

    printOn($token)->assertCreated();

    $document = Document::query()->withoutGlobalScope(OrgScope::class)->inPrintTray()->firstOrFail();
    expect($document->org_id)->toBe($owner->org_id)
        ->and($document->uploaded_by)->toBe($owner->user_id)
        ->and($document->title)->toBe('Rechnung.docx');

    $this->actingAs($owner->user)->get('/inbox?source=printer')->assertOk()->assertSee('Rechnung.docx');
    $this->get('/inbox?source=upload')->assertOk()->assertDontSee('Rechnung.docx');
    $this->get('/print-tray')->assertRedirect('/inbox?source=printer');
    $this->get('/files/'.$document->files()->withoutGlobalScope(OrgScope::class)->firstOrFail()->id)->assertOk();
});

it('refuses prints without a valid token, from a locked computer or a former member', function (): void {
    $owner = Membership::factory()->owner()->create();
    $token = pairPrinter($owner);

    printOn('wrong')->assertUnauthorized();

    $owner->update(['revoked_at' => now()]);
    printOn($token)->assertForbidden();
    $owner->update(['revoked_at' => null]);

    $printer = Printer::query()->withoutGlobalScope(OrgScope::class)->whereNotNull('token_hash')->firstOrFail();
    $this->actingAs($owner->user)->post('/settings/eingang/printer/'.$printer->id.'/delete')->assertRedirect('/settings/eingang/printer');
    printOn($token)->assertUnauthorized();
});

it('shows Einstellungen → Drucker to people who may upload, with their own computers only', function (): void {
    $owner = Membership::factory()->owner()->create();
    pairPrinter($owner);
    $colleague = Membership::factory()->create(['org_id' => $owner->org_id]);

    $this->actingAs($owner->user)->get('/settings/eingang/printer')->assertOk()->assertSee('BUERO-PC')->assertSee('gekoppelt am');
    $this->get('/settings/printer')->assertRedirect('/settings/eingang/printer');
    $this->actingAs($colleague->user)->get('/settings/eingang/printer')->assertOk()->assertDontSee('BUERO-PC');
    $this->actingAs(Membership::factory()->accountant()->create()->user)->get('/settings/eingang/printer')->assertForbidden();
});

it('deletes only own pairings and keeps the row for the audit trail', function (): void {
    $owner = Membership::factory()->owner()->create();
    $token = pairPrinter($owner);
    $printer = Printer::query()->withoutGlobalScope(OrgScope::class)->whereNotNull('token_hash')->firstOrFail();

    $colleague = Membership::factory()->create(['org_id' => $owner->org_id]);
    $this->actingAs($colleague->user)->post('/settings/eingang/printer/'.$printer->id.'/delete')->assertNotFound();
    printOn($token)->assertCreated();

    $this->actingAs($owner->user)->post('/settings/eingang/printer/'.$printer->id.'/delete')->assertRedirect('/settings/eingang/printer');
    $this->get('/settings/eingang/printer')->assertDontSee('delete-printer-'.$printer->id);
    expect(Printer::query()->withoutGlobalScope(OrgScope::class)->find($printer->id)?->revoked_at)->not->toBeNull();
    printOn($token)->assertUnauthorized();
});

it('replaces the older pairing when the same computer is paired again', function (): void {
    $owner = Membership::factory()->owner()->create();
    $old = pairPrinter($owner);
    $new = pairPrinter($owner);

    expect(Printer::query()->withoutGlobalScope(OrgScope::class)->whereNotNull('token_hash')->whereNull('revoked_at')->count())->toBe(1);
    printOn($old)->assertUnauthorized();
    printOn($new)->assertCreated();
});

it('points the empty printer tab to Einstellungen → Eingang → Drucker until a printer is paired', function (): void {
    $owner = Membership::factory()->owner()->create();

    $this->actingAs($owner->user)->get('/inbox?source=printer')->assertOk()->assertSee('data-no-printer', false);
    pairPrinter($owner);
    $this->actingAs($owner->user)->get('/inbox?source=printer')->assertOk()->assertDontSee('data-no-printer', false);
});
