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
| Druckerablage, from there they become receipts or go to the trash.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(fn () => Storage::fake('local'));

/** Downloads the installer and links a computer with its code; returns the token. */
function pairPrinter(Membership $member): string
{
    $download = test()->actingAs($member->user)->post('/print-tray/printers');
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

    $script = (string) $this->actingAs($owner->user)->post('/print-tray/printers')->assertOk()->getContent();

    expect($script)->toStartWith("<# : Hamster-Drucker-einrichten.cmd\r\n")
        ->toContain("\$ServerUrl = '".rtrim((string) config('app.url'), '/')."'")
        ->not->toContain('__PAIRING_CODE__')
        ->and(preg_match("/(?<!\r)\n/", $script))->toBe(0);

    $token = pairPrinter($owner);
    expect($token)->toHaveLength(48);
});

it('takes a code only once', function (): void {
    $owner = Membership::factory()->owner()->create();
    $download = (string) $this->actingAs($owner->user)->post('/print-tray/printers')->getContent();
    preg_match("/\\\$PairingCode = '([A-Za-z0-9]+)'/", $download, $code);

    $this->postJson('/api/printer/pair', ['code' => $code[1]])->assertCreated();
    $this->postJson('/api/printer/pair', ['code' => $code[1]])->assertNotFound();
});

it('puts a print into the Druckerablage, not into the receipts', function (): void {
    $owner = Membership::factory()->owner()->create();
    $token = pairPrinter($owner);

    printOn($token)->assertCreated();

    $document = Document::query()->withoutGlobalScope(OrgScope::class)->inPrintTray()->firstOrFail();
    expect($document->org_id)->toBe($owner->org_id)
        ->and($document->uploaded_by)->toBe($owner->user_id)
        ->and($document->title)->toBe('Rechnung.docx');

    $this->actingAs($owner->user)->get('/print-tray')->assertOk()->assertSee('Rechnung.docx')->assertSee('BUERO-PC');
    $this->get('/documents')->assertOk()->assertDontSee('Rechnung.docx');
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
    $this->actingAs($owner->user)->post('/print-tray/printers/'.$printer->id.'/revoke')->assertRedirect('/print-tray');
    printOn($token)->assertUnauthorized();
});

it('takes a print over as receipt, or sends it to the trash and back to the tray', function (): void {
    $owner = Membership::factory()->owner()->create();
    $token = pairPrinter($owner);
    printOn($token, 'Bon A');
    printOn($token, 'Bon B');
    [$a, $b] = Document::query()->withoutGlobalScope(OrgScope::class)->inPrintTray()->orderBy('title')->get()->all();

    $stranger = Membership::factory()->owner()->create();
    $this->actingAs($stranger->user)->post('/print-tray/'.$a->id.'/accept')->assertNotFound();

    $this->actingAs($owner->user)->post('/print-tray/'.$a->id.'/accept')->assertRedirect('/print-tray');
    $this->get('/documents')->assertSee('Bon A')->assertDontSee('Bon B');

    $this->post('/print-tray/'.$b->id.'/delete')->assertRedirect('/print-tray');
    $this->get('/documents/trash')->assertSee('Bon B');
    $this->post('/documents/'.$b->id.'/restore');
    $this->get('/print-tray')->assertSee('Bon B');
});

it('counts new prints in the menu badge until the Druckerablage is opened', function (): void {
    $owner = Membership::factory()->owner()->create();
    $token = pairPrinter($owner);
    printOn($token)->assertCreated();

    $this->actingAs($owner->user)->getJson('/badges')->assertOk()->assertExactJson(['print-tray' => 1]);
    $this->get('/')->assertSee('data-badge="print-tray"', false);

    $this->travel(1)->seconds();
    $this->get('/print-tray')->assertOk();
    $this->getJson('/badges')->assertExactJson(['print-tray' => 0]);

    $this->travel(1)->seconds();
    printOn($token)->assertCreated();
    $this->actingAs($owner->user)->getJson('/badges')->assertExactJson(['print-tray' => 1]);
});
