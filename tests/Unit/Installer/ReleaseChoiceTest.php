<?php

declare(strict_types=1);

use Tests\Unit\Installer\InstallerFixtures;

InstallerFixtures::loadInstaller();

it('picks the newest fitting release and adds the zip URL', function (): void {
    $fetched = [];
    $fetch = function (string $url) use (&$fetched): array {
        $fetched[] = $url;

        return InstallerFixtures::makeManifest('0.2.0');
    };

    $manifest = selectRelease([
        InstallerFixtures::makeRelease('0.2.0'),
        InstallerFixtures::makeRelease('0.1.0'),
    ], $fetch, '8.3.0');

    expect($manifest['version'])->toBe('0.2.0')
        ->and($manifest['url'])->toBe('https://example.com/0.2.0/dochamster-0.2.0.zip')
        ->and($fetched)->toBe(['https://example.com/0.2.0/manifest.json']);
});

it('skips drafts and prereleases without fetching their manifests', function (): void {
    $fetched = [];
    $fetch = function (string $url) use (&$fetched): array {
        $fetched[] = $url;

        return InstallerFixtures::makeManifest('0.1.0');
    };

    $manifest = selectRelease([
        InstallerFixtures::makeRelease('0.3.0', ['draft' => true]),
        InstallerFixtures::makeRelease('0.2.0', ['prerelease' => true]),
        InstallerFixtures::makeRelease('0.1.0'),
    ], $fetch, '8.3.0');

    expect($manifest['version'])->toBe('0.1.0')
        ->and($fetched)->toBe(['https://example.com/0.1.0/manifest.json']);
});

it('skips releases that cannot be installed', function (mixed $release, mixed $manifest): void {
    $fetch = static fn (string $url): mixed => str_contains($url, '/0.1.0/')
        ? InstallerFixtures::makeManifest('0.1.0')
        : $manifest;

    $chosen = selectRelease([$release, InstallerFixtures::makeRelease('0.1.0')], $fetch, '8.3.0');

    expect($chosen['version'])->toBe('0.1.0');
})->with([
    'no manifest asset' => [
        InstallerFixtures::makeRelease('0.2.0', ['assets' => [
            ['name' => 'dochamster-0.2.0.zip', 'browser_download_url' => 'https://example.com/0.2.0/dochamster-0.2.0.zip'],
        ]]),
        InstallerFixtures::makeManifest('0.2.0'),
    ],
    'manifest points at a missing zip' => [
        InstallerFixtures::makeRelease('0.2.0'),
        ['version' => '0.2.0', 'php' => '8.3.0', 'zip' => 'dochamster-9.9.9.zip', 'sha256' => str_repeat('b', 64)],
    ],
    'PHP too new' => [
        InstallerFixtures::makeRelease('0.2.0'),
        InstallerFixtures::makeManifest('0.2.0', '99.0.0'),
    ],
    'manifest is not JSON' => [
        InstallerFixtures::makeRelease('0.2.0'),
        'API rate limit exceeded',
    ],
    'release row is not an array' => [
        'v0.2.0',
        InstallerFixtures::makeManifest('0.2.0'),
    ],
]);

it('accepts an exact PHP version match', function (): void {
    $manifest = selectRelease(
        [InstallerFixtures::makeRelease('0.2.0')],
        static fn (): array => InstallerFixtures::makeManifest('0.2.0', '8.3.0'),
        '8.3.0'
    );

    expect($manifest['version'])->toBe('0.2.0');
});

it('throws when no release fits', function (): void {
    selectRelease(
        [InstallerFixtures::makeRelease('0.2.0')],
        static fn (): array => InstallerFixtures::makeManifest('0.2.0', '99.0.0'),
        '8.3.0'
    );
})->throws(RuntimeException::class, '8.3.0');

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
