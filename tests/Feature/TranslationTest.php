<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Translate\DocumentTranslation;
use App\Domain\Translate\Translations;
use App\Infrastructure\Extensions\Catalogue;
use App\Infrastructure\Extensions\Extensions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

/*
| Point 172: translation packs (pinned, runtime and pairs installed along), the route over English, the source
| language, and stored translations (one per target language, found by the search in the recognised text).
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/dh-tr-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->root);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

/** Marks catalogue entries as installed with dummy files (key => auto). */
function fakeInstalled(string $root, array $keys): void
{
    $installed = [];
    foreach ($keys as $key => $auto) {
        $ext = Catalogue::find($key);
        foreach ($ext->paths() as $path) {
            File::ensureDirectoryExists(dirname($root.'/'.$path));
            file_put_contents($root.'/'.$path, 'x');
        }
        $installed[$key] = ['version' => $ext->version, 'files' => $ext->paths(), 'enabled' => true, 'auto' => $auto, 'at' => ''];
    }
    file_put_contents($root.'/extensions.json', json_encode(['v' => 1, 'installed' => $installed]));
}

it('pins Mozilla files and installs the runtime and both pairs along with "Griechisch → Deutsch"', function (): void {
    foreach (['translate-engine', 'translate-el-en', 'translate-en-el', 'translate-en-de', 'translate-de-en'] as $key) {
        foreach (Catalogue::find($key)->files as $file) {
            expect($file['url'])->toStartWith('https://firefox-settings-attachments.cdn.mozilla.net/');
        }
    }
    expect(Catalogue::find('translate-el-de')->requires)->toBe(['translate-el-en', 'translate-en-de'])
        ->and(Catalogue::find('translate-engine')->listed())->toBeFalse();

    $urls = [];
    $fetch = static function (string $url) use (&$urls): void {
        $urls[] = $url;
        throw new RuntimeException('offline');
    };
    expect(fn () => (new Extensions($this->root))->install('translate-el-de', $fetch))->toThrow(RuntimeException::class, 'offline')
        ->and($urls[0])->toBe(Catalogue::find('translate-engine')->files[0]['url']);
});

it('removes a bundle with what came along, but keeps what was installed on its own', function (): void {
    fakeInstalled($this->root, ['translate-engine' => true, 'translate-el-en' => true, 'translate-en-de' => false, 'translate-el-de' => false]);
    $extensions = new Extensions($this->root);
    expect($extensions->isInstalled('translate-el-de'))->toBeTrue();

    $extensions->remove('translate-el-de');

    expect(array_keys($extensions->installed()))->toBe(['translate-engine', 'translate-en-de'])
        ->and(is_file($this->root.'/translate/el-en/'.Catalogue::find('translate-el-en')->files[0]['name']))->toBeFalse();

    $extensions->remove('translate-en-de');
    expect($extensions->installed())->toBe([]);
});

it('routes directly or over English and offers only reachable targets', function (): void {
    $pairs = ['el-en' => 1, 'en-de' => 1, 'de-en' => 1];
    expect(Translations::route('el', 'de', $pairs))->toBe(['el-en', 'en-de'])
        ->and(Translations::route('el', 'en', $pairs))->toBe(['el-en'])
        ->and(Translations::route('de', 'el', $pairs))->toBeNull()
        ->and(Translations::route('de', 'de', $pairs))->toBeNull();

    fakeInstalled($this->root, ['translate-engine' => true, 'translate-el-en' => false]);
    $translations = new Translations(new Extensions($this->root));
    expect(array_keys($translations->targets('el')))->toBe(['en']);
});

it('detects the source language', function (): void {
    expect(Translations::source(null, 'ΑΠΟΔΕΙΞΗ ΛΙΑΝΙΚΗΣ ΣΥΝΟΛΟ 12,50'))->toBe('el')
        ->and(Translations::source(null, 'Rechnung Summe gesamt, bitte den Betrag überweisen'))->toBe('de')
        ->and(Translations::source(null, 'Thank you for your order, the total amount is'))->toBe('en')
        ->and(Translations::source('eng', 'Rechnung 12,50'))->toBe('en');
});

it('stores one translation per language and finds it in the search of the recognised text', function (): void {
    $member = Membership::factory()->owner()->create();
    $document = Document::query()->withoutGlobalScope(OrgScope::class)
        ->create(['org_id' => $member->org_id, 'uploaded_by' => $member->user_id, 'title' => 'beleg.jpg']);
    $document->forceFill(['ocr_status' => 'done', 'ocr_text' => 'ΣΥΝΟΛΟ 12,50'])->save();

    $this->actingAs($member->user);
    $url = '/documents/'.$document->id.'/translations';
    $this->postJson($url, ['from' => 'el', 'to' => 'de', 'text' => 'Gesamt 12,50'])->assertOk();
    $this->postJson($url, ['from' => 'el', 'to' => 'de', 'text' => 'Summe 12,50'])->assertOk();
    $this->postJson($url, ['from' => 'el', 'to' => 'el', 'text' => 'x'])->assertUnprocessable();

    expect(DocumentTranslation::query()->where('document_id', $document->id)->pluck('text')->all())->toBe(['Summe 12,50']);
    $this->get('/documents?q=Summe&ocr=1')->assertSee('beleg.jpg');
    $this->get('/documents?q=Summe')->assertDontSee('beleg.jpg');
    $this->get('/documents/'.$document->id)->assertOk()->assertSee('Summe 12,50')->assertDontSee('data-translate-run', false);

    fakeInstalled($this->root, ['translate-engine' => true, 'translate-el-en' => false]);
    $this->app->instance(Extensions::class, new Extensions($this->root));
    $this->get('/documents/'.$document->id)->assertOk()->assertSee('data-translate-run', false)->assertSee('translate\/files\/translate-el-en', false);
    $this->get('/settings/extensions')->assertOk()->assertSee('Übersetzung: Griechisch → Deutsch')->assertDontSee('translate-engine/install');
});
