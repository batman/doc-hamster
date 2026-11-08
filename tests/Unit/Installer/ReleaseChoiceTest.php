<?php

declare(strict_types=1);

use Tests\Unit\Installer\InstallerFixtures;

InstallerFixtures::loadInstaller();

it('takes the latest manifest and adds the zip download address', function (): void {
    $release = releaseFromManifest(InstallerFixtures::makeManifest('0.2.0'), '8.3.0');

    expect($release['version'])->toBe('0.2.0')
        ->and($release['url'])->toBe('https://github.com/batman/doc-hamster/releases/download/v0.2.0/dochamster-0.2.0.zip');
});

it('accepts an exact PHP version match', function (): void {
    expect(releaseFromManifest(InstallerFixtures::makeManifest('0.2.0', '8.3.0'), '8.3.0')['version'])->toBe('0.2.0');
});

it('throws when the latest release needs a newer PHP', function (): void {
    releaseFromManifest(InstallerFixtures::makeManifest('0.2.0', '99.0.0'), '8.3.0');
})->throws(RuntimeException::class, '8.3.0');

it('refuses manifests it cannot use', function (mixed $manifest): void {
    releaseFromManifest($manifest, '8.3.0');
})->throws(RuntimeException::class)->with([
    'not JSON' => [null],
    'no zip' => [['version' => '0.2.0', 'php' => '8.3.0']],
    'zip with a path' => [['version' => '0.2.0', 'php' => '8.3.0', 'zip' => '../evil.zip']],
]);

it('rewrites every path in the real public index into valid PHP', function (): void {
    $index = (string) file_get_contents(dirname(__DIR__, 3).'/public/index.php');
    expect(substr_count($index, "__DIR__.'/../"))->toBeGreaterThan(1);

    $rewritten = rewriteIndexPhp($index, '/srv/dochamster');

    expect($rewritten)->not->toContain("__DIR__.'/../")
        ->and($rewritten)->toContain("'/srv/dochamster/'")
        ->and(InstallerFixtures::lints($rewritten))->toBeTrue();
});

it('quotes hostile app paths so the rewritten index stays valid', function (): void {
    $index = "<?php require __DIR__.'/../vendor/autoload.php';";

    $rewritten = rewriteIndexPhp($index, "/srv/o'brien\\dochamster\$x");

    expect($rewritten)->not->toContain("__DIR__.'/../")
        ->and($rewritten)->toContain(var_export("/srv/o'brien\\dochamster\$x/", true))
        ->and(InstallerFixtures::lints($rewritten))->toBeTrue();
});

it('leaves an index without the placeholder unchanged', function (): void {
    expect(rewriteIndexPhp('<?php echo 1;', '/srv/dochamster'))->toBe('<?php echo 1;');
});
