<?php

declare(strict_types=1);

use App\Domain\Devices\DeviceKey;
use App\Domain\Documents\Document;
use App\Domain\Documents\ShareIntake;
use App\Domain\Documents\TextPdf;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Points 151, 152, A328, A330: device keys upload and nothing else, the share target stores at once,
| text or a link alone becomes a small PDF.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(fn () => Storage::fake('local'));

function addDevice(Membership $member, string $kind = 'iphone'): string
{
    $response = test()->actingAs($member->user)->post('/settings/devices', ['name' => 'iPhone von Max', 'kind' => $kind]);
    $response->assertRedirect('/settings/devices')->assertSessionHas('device_key');

    return (string) session('device_key')['key'];
}

function pdfFile(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('rechnung.pdf', "%PDF-1.4\n%synthetic\n");
}

it('stores only the hash and uploads with the key as phone or api', function (): void {
    $owner = Membership::factory()->owner()->create();
    $key = addDevice($owner);
    $program = addDevice($owner, 'program');

    $device = DeviceKey::query()->withoutGlobalScope(OrgScope::class)->where('kind', 'iphone')->firstOrFail();
    expect($device->key_hash)->toBe(hash('sha256', $key))->not->toContain($key);

    app('auth')->forgetGuards();
    $this->withToken($key)->post('/api/upload', ['file' => pdfFile()], ['Accept' => 'application/json'])
        ->assertCreated()->assertJson(['saved' => 1, 'message' => 'Gespeichert']);
    $this->withToken($program)->post('/api/upload', ['files' => [pdfFile(), pdfFile()]], ['Accept' => 'application/json'])
        ->assertCreated()->assertJson(['saved' => 2]);

    $sources = Document::query()->withoutGlobalScope(OrgScope::class)->pluck('source')->countBy()->all();
    expect($sources)->toBe(['phone' => 1, 'api' => 2])
        ->and($device->fresh()?->last_used_at)->not->toBeNull();
});

it('refuses wrong and revoked keys, and the key reads nothing', function (): void {
    $owner = Membership::factory()->owner()->create();
    $key = addDevice($owner);
    $device = DeviceKey::query()->withoutGlobalScope(OrgScope::class)->firstOrFail();

    app('auth')->forgetGuards();
    $this->withToken('dhk_wrong')->post('/api/upload', ['file' => pdfFile()], ['Accept' => 'application/json'])->assertUnauthorized();
    $this->withToken($key)->get('/documents')->assertRedirect('/login');

    $this->actingAs($owner->user)->post('/settings/devices/'.$device->id.'/delete')->assertRedirect('/settings/devices');
    app('auth')->forgetGuards();
    $this->withToken($key)->post('/api/upload', ['file' => pdfFile()], ['Accept' => 'application/json'])->assertUnauthorized();
});

it('reads title and body of a text-only share', function (): void {
    expect(ShareIntake::textOf('', '', ''))->toBeNull()
        ->and(ShareIntake::textOf(null, "Einkaufsliste\nMilch", null))->toBe(['title' => 'Einkaufsliste', 'body' => "Einkaufsliste\nMilch"])
        ->and(ShareIntake::textOf('', '', 'https://example.com/rechnung/42'))->toBe(['title' => 'example.com', 'body' => 'https://example.com/rechnung/42'])
        ->and(ShareIntake::textOf('Angebot', 'Siehe https://example.com/a', 'https://example.com/a'))->toBe(['title' => 'Angebot', 'body' => 'Siehe https://example.com/a'])
        ->and(ShareIntake::textOf('Angebot', 'Bitte ansehen', 'https://example.com/a')['body'])->toBe("Bitte ansehen\n\nhttps://example.com/a");
});

it('writes a valid PDF from text, umlauts and several pages included', function (): void {
    $pdf = TextPdf::make('Grüße (Test)', str_repeat("Zeile mit Ümlaut\n", 120));

    expect($pdf)->toStartWith('%PDF-1.4')->toEndWith("%%EOF\n")
        ->toContain("(Gr\xFC\xDFe \\(Test\\)) Tj")->toContain('/Count 3');
    preg_match('/startxref\n(\d+)/', $pdf, $m);
    expect(substr($pdf, (int) $m[1], 4))->toBe('xref');
    preg_match_all('/(\d{10}) 00000 n /', $pdf, $offsets);
    foreach ($offsets[1] as $i => $offset) {
        expect(substr($pdf, (int) $offset, strlen(($i + 1).' 0 obj')))->toBe(($i + 1).' 0 obj');
    }
});

it('stores a shared text as PDF document from the phone and lists it on the confirmation', function (): void {
    $owner = Membership::factory()->owner()->create();

    $this->actingAs($owner->user)->post('/share', ['title' => 'Notiz', 'text' => 'Termin beim Steuerbüro'], ['Accept' => 'application/json'])
        ->assertOk()->assertJson(['saved' => 1, 'redirect' => route('share.done')]);

    $document = Document::query()->firstOrFail();
    expect($document->source)->toBe('phone')->and($document->title)->toBe('Notiz')
        ->and($document->files()->firstOrFail()->mime)->toBe('application/pdf');
    $this->get('/share/done')->assertOk()->assertSee('1 Dokument gespeichert');
});
