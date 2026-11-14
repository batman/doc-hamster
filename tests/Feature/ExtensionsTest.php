<?php

declare(strict_types=1);

use App\Domain\Documents\Document;
use App\Domain\Org\Membership;
use App\Domain\Org\OrgScope;
use App\Domain\Org\RoleRight;
use App\Infrastructure\Extensions\Extensions;
use App\Jobs\Local\RecognizeText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

/*
| Point 173.3: install only verified files, state in extensions.json, removing deletes exactly the listed files.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/dh-ext-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->root.'/ocr/tessdata');
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('refuses a file with the wrong checksum and keeps the state empty', function (): void {
    $extensions = new Extensions($this->root);
    $fetch = static function (string $url, string $sha, string $target): void {
        file_put_contents($target, 'not the pinned file');
    };

    expect(fn () => $extensions->install('ocr-ell', $fetch))->toThrow(RuntimeException::class, 'Prüfsumme falsch')
        ->and(is_file($this->root.'/ocr/tessdata/ell.traineddata'))->toBeFalse()
        ->and($extensions->installed())->toBe([]);
});

it('does not install unknown keys or modules', function (): void {
    $extensions = new Extensions($this->root);
    $fetch = static fn (string $u, string $s, string $t): null => null;

    expect(fn () => $extensions->install('nope', $fetch))->toThrow(RuntimeException::class)
        ->and(fn () => $extensions->install('revolut', $fetch))->toThrow(RuntimeException::class, 'Noch nicht verfügbar');
});

it('switches off, removes and only deletes listed files inside the root', function (): void {
    file_put_contents($this->root.'/ocr/tessdata/ell.traineddata', 'x');
    file_put_contents($this->root.'/keep.txt', 'x');
    file_put_contents($this->root.'/extensions.json', json_encode(['v' => 1, 'installed' => [
        'ocr-ell' => ['version' => '4.1.0', 'files' => ['ocr/tessdata/ell.traineddata', '../'.basename($this->root).'/keep.txt'], 'enabled' => true, 'at' => ''],
    ]]));
    $extensions = new Extensions($this->root);

    expect($extensions->isInstalled('ocr-ell'))->toBeTrue()
        ->and(array_map(fn ($e) => $e->key, $extensions->enabled('ocr')))->toBe(['ocr-ell']);

    $extensions->setEnabled('ocr-ell', false);
    expect($extensions->enabled('ocr'))->toBe([])
        ->and($extensions->installed()['ocr-ell']['enabled'])->toBeFalse();

    $extensions->remove('ocr-ell');
    expect(is_file($this->root.'/ocr/tessdata/ell.traineddata'))->toBeFalse()
        ->and(is_file($this->root.'/keep.txt'))->toBeTrue()
        ->and($extensions->installed())->toBe([]);
});

it('shows the page to admins, re-reads broken texts (A346) and keeps a valid language per document (A345)', function (): void {
    Queue::fake();
    file_put_contents($this->root.'/ocr/tessdata/ell.traineddata', 'x');
    file_put_contents($this->root.'/extensions.json', json_encode(['v' => 1, 'installed' => [
        'ocr-ell' => ['version' => '4.1.0', 'files' => ['ocr/tessdata/ell.traineddata'], 'enabled' => true, 'at' => ''],
    ]]));
    $this->app->instance(Extensions::class, new Extensions($this->root));
    $admin = Membership::factory()->owner()->create();
    $document = Document::query()->withoutGlobalScope(OrgScope::class)
        ->create(['org_id' => $admin->org_id, 'uploaded_by' => $admin->user_id, 'title' => 'gr.jpg']);
    $document->forceFill(['ocr_status' => 'done', 'ocr_text' => 'AoyaplaoytoU TlpoovtwV EkboonG TImoAoyiou ApiBuoG MeplypaonV TloooTnTa EmrtpoonG KaBapnG AEiaG ZuvoAikoV'])->save();

    $this->actingAs($admin->user)->get('/settings/extensions')->assertOk()
        ->assertSee('Texterkennung: Griechisch')->assertSee('kommt später')->assertSee('1 Dokumente neu erkennen');
    $this->post('/settings/extensions/ocr-rerun')->assertRedirect();
    Queue::assertPushed(RecognizeText::class, 1);
    expect($document->refresh()->ocr_status)->toBe('pending');

    $this->post('/documents/'.$document->id.'/ocr', ['langs' => 'ell'])->assertRedirect();
    expect($document->refresh()->getAttribute('ocr_langs'))->toBe('ell');
    $this->get('/documents/'.$document->id)->assertOk()->assertSee('Alle Sprachen')->assertSee('Griechisch');
    $this->post('/documents/'.$document->id.'/ocr', ['langs' => 'tur'])->assertRedirect();
    expect($document->refresh()->getAttribute('ocr_langs'))->toBeNull();

    $clerk = Membership::factory()->withRights(RoleRight::Upload)->create(['org_id' => $admin->org_id]);
    $this->actingAs($clerk->user)->get('/settings/extensions')->assertForbidden();
});

it('verifies size and checksum', function (): void {
    $file = $this->root.'/f';
    file_put_contents($file, 'abc');
    $sha = hash('sha256', 'abc');

    expect(Extensions::verify($file, $sha, 3))->toBeTrue()
        ->and(Extensions::verify($file, $sha, 4))->toBeFalse()
        ->and(Extensions::verify($file, str_repeat('0', 64), 3))->toBeFalse();
});
