<?php

declare(strict_types=1);

use App\Domain\Org\Membership;
use App\Jobs\Net\ModuleSource;
use App\Modules\InstalledModules;
use App\Modules\Manifest;
use App\Modules\ModuleConnections;
use App\Modules\ModuleInstaller;
use App\Modules\ModuleLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/*
| D-041: the Steckbrief is checked, a zip only unpacks below the module folder, the template module installs, loads
| (settings page, ⋯ entry, its table), a broken one is switched off, and removing with data drops its tables.
*/
uses(RefreshDatabase::class)->group('db');

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/dh-mod-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->root);
    $this->app->instance(InstalledModules::class, new InstalledModules($this->root));
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

/** The template module (docs/modules/template) as module.zip, like its release workflow packs it. */
function templateZip(string $root, ?Closure $change = null): string
{
    $zipFile = $root.'/module.zip';
    $zip = new ZipArchive;
    $zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $base = base_path('docs/modules/template');
    foreach (File::allFiles($base, true) as $file) {
        $name = str_replace('\\', '/', $file->getRelativePathname());
        if (! str_starts_with($name, '.github/')) {
            $zip->addFile($file->getPathname(), $name);
        }
    }
    if ($change !== null) {
        $change($zip);
    }
    $zip->close();

    return $zipFile;
}

function templateManifest(): Manifest
{
    return Manifest::from(json_decode((string) file_get_contents(base_path('docs/modules/template/dochamster-module.json')), true));
}

it('checks the Steckbrief and knows a GitHub address', function (): void {
    $manifest = templateManifest();
    expect($manifest->namespace())->toBe('DocHamsterModules\\Beispiel\\')
        ->and($manifest->tablePrefix())->toBe('m_beispiel_')
        ->and($manifest->fits())->toBeTrue();
    expect(fn () => Manifest::from(['key' => '../x', 'name' => 'x', 'description' => 'x', 'version' => '1.0.0', 'provider' => 'x']))->toThrow(InvalidArgumentException::class);
    expect(fn () => Manifest::from([...$manifest->toArray(), 'rights' => ['everything']]))->toThrow(InvalidArgumentException::class);

    expect(ModuleSource::repo('https://github.com/example/dochamster-modul'))->toBe('example/dochamster-modul')
        ->and(ModuleSource::repo('github.com/example/x.git'))->toBe('example/x')
        ->and(ModuleSource::repo('https://example.com/a/b'))->toBeNull();
});

it('installs the template module, plugs it in and removes it with its data', function (): void {
    $owner = Membership::factory()->owner()->create();
    $installer = app(ModuleInstaller::class);
    $manifest = templateManifest();

    $installer->install($manifest, 'example/dochamster-beispiel', ['documents_read'], templateZip($this->root));
    expect(app(InstalledModules::class)->find('beispiel'))->not->toBeNull()
        ->and(Schema::hasTable('m_beispiel_notes'))->toBeTrue();

    app(ModuleLoader::class)->boot();
    $connections = app(ModuleConnections::class);
    expect(array_column($connections->settings, 'route'))->toBe(['m.beispiel.settings'])
        ->and(array_column($connections->documentActions, 'route'))->toBe(['m.beispiel.document']);

    $this->actingAs($owner->user)->get('/m/beispiel/settings')->assertOk()->assertSee('Beispiel-Modul');

    $installer->remove('beispiel', true);
    expect(app(InstalledModules::class)->find('beispiel'))->toBeNull()
        ->and(Schema::hasTable('m_beispiel_notes'))->toBeFalse();
});

it('refuses a zip with paths outside the module folder and a module asking for more than agreed', function (): void {
    $zip = templateZip($this->root, static fn (ZipArchive $z) => $z->addFromString('../evil.php', '<?php'));
    expect(fn () => app(ModuleInstaller::class)->install(templateManifest(), 'example/x', [], $zip))->toThrow(RuntimeException::class);
    expect(is_file(dirname($this->root).'/evil.php'))->toBeFalse()
        ->and(app(InstalledModules::class)->find('beispiel'))->toBeNull();

    expect(fn () => app(ModuleInstaller::class)->agreed(templateManifest(), 'beispiel', []))->toThrow(RuntimeException::class);
});

it('switches a module off when it no longer fits or throws, and docHamster keeps running', function (): void {
    $modules = app(InstalledModules::class);
    $modules->save(Manifest::from([...templateManifest()->toArray(), 'api' => 99]), 'example/x', []);
    app(ModuleLoader::class)->boot();
    expect($modules->find('beispiel')['enabled'])->toBeFalse()
        ->and($modules->find('beispiel')['error'])->toContain('99');

    $modules->save(Manifest::from([...templateManifest()->toArray(), 'key' => 'leer']), 'example/x', []);
    // No code in its folder: the entry class is missing.
    app(ModuleLoader::class)->boot();
    expect($modules->find('leer')['enabled'])->toBeFalse()
        ->and($modules->find('leer')['error'])->toContain('Startklasse');
});
