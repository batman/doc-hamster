<?php

declare(strict_types=1);

use Tests\Unit\Installer\InstallerBrowser;
use Tests\Unit\Installer\InstallerFixtures;

InstallerFixtures::loadInstaller();

it('reuses the cached release without calling the chooser', function (): void {
    $session = ['release' => InstallerFixtures::makeManifest('0.2.0')];
    $calls = 0;
    $choose = function () use (&$calls): array {
        $calls++;

        return InstallerFixtures::makeManifest('0.3.0');
    };

    $release = loadRelease($session, $choose, 1000);

    expect($release['version'])->toBe('0.2.0')
        ->and($calls)->toBe(0);
});

it('chooses once and caches the result', function (): void {
    $session = [];
    $calls = 0;
    $choose = function () use (&$calls): array {
        $calls++;

        return InstallerFixtures::makeManifest('0.2.0');
    };

    $first = loadRelease($session, $choose, 1000);
    $second = loadRelease($session, $choose, 1100);

    expect($first)->toBe($second)
        ->and($calls)->toBe(1);
});

it('caches failures within the cooldown', function (): void {
    $session = [];
    $calls = 0;
    $choose = function () use (&$calls): array {
        $calls++;

        throw new RuntimeException('GitHub hat gerade zu viele Anfragen von diesem Server bekommen.');
    };

    $messages = [];
    foreach ([1000, 1100, 1299] as $now) {
        try {
            loadRelease($session, $choose, $now);
            $messages[] = null;
        } catch (RuntimeException $e) {
            $messages[] = $e->getMessage();
        }
    }

    expect($messages)->toBe([
        'GitHub hat gerade zu viele Anfragen von diesem Server bekommen.',
        'GitHub hat gerade zu viele Anfragen von diesem Server bekommen.',
        'GitHub hat gerade zu viele Anfragen von diesem Server bekommen.',
    ])->and($calls)->toBe(1);
});

it('retries the lookup after the cooldown', function (): void {
    $session = [];
    $calls = 0;
    $choose = function () use (&$calls): array {
        $calls++;

        throw new RuntimeException('Download fehlgeschlagen (HTTP 500).');
    };

    foreach ([1000, 1000 + DH_RELEASE_RETRY_SECONDS] as $now) {
        try {
            loadRelease($session, $choose, $now);
        } catch (RuntimeException) {
        }
    }

    expect($calls)->toBe(2);
});

it('recovers after the cooldown and caches the recovery', function (): void {
    $session = [];
    $calls = 0;
    $choose = function () use (&$calls): array {
        $calls++;
        if ($calls === 1) {
            throw new RuntimeException('Download fehlgeschlagen (HTTP 500).');
        }

        return InstallerFixtures::makeManifest('0.2.0');
    };

    try {
        loadRelease($session, $choose, 1000);
        $failed = false;
    } catch (RuntimeException) {
        $failed = true;
    }
    $recovered = loadRelease($session, $choose, 1000 + DH_RELEASE_RETRY_SECONDS);
    $again = loadRelease($session, $choose, 1100 + DH_RELEASE_RETRY_SECONDS);

    expect($failed)->toBeTrue()
        ->and($recovered['version'])->toBe('0.2.0')
        ->and($again)->toBe($recovered)
        ->and($calls)->toBe(2);
});

it('ignores a non-array cached release', function (): void {
    $session = ['release' => 'junk'];
    $calls = 0;
    $choose = function () use (&$calls): array {
        $calls++;

        return InstallerFixtures::makeManifest('0.2.0');
    };

    $release = loadRelease($session, $choose, 1000);

    expect($release['version'])->toBe('0.2.0')
        ->and($calls)->toBe(1);
});

it('ignores a failure without a timestamp', function (): void {
    $session = ['release_error' => 'stale'];
    $calls = 0;
    $choose = function () use (&$calls): array {
        $calls++;

        return InstallerFixtures::makeManifest('0.2.0');
    };

    $release = loadRelease($session, $choose, 1000);

    expect($release['version'])->toBe('0.2.0')
        ->and($calls)->toBe(1);
});

it('shows the translated rate-limit message without hammering the API', function (): void {
    $routes = ['GET /api/gh' => ['status' => 403, 'body' => '{"message":"API rate limit exceeded"}']];
    $browser = InstallerBrowser::start($routes);
    try {
        $first = $browser->unlock();

        expect($first)->toContain('zu viele Anfragen');

        $second = $browser->get('/install.php')[2];

        expect($second)->toContain('zu viele Anfragen')
            ->and(trim($browser->hits()))->toBe('GET '.InstallerBrowser::LATEST_MANIFEST);
    } finally {
        $browser->stop();
    }
});

it('fetches a fitting release once per session', function (): void {
    [$routes, $files] = InstallerBrowser::releaseRoutes('0.2.0', '8.3.0', str_repeat('a', 64));
    $browser = InstallerBrowser::start($routes, $files);
    try {
        $first = $browser->unlock();

        expect($first)->toContain('Passende Version');

        $browser->get('/install.php');

        // One manifest download for two page loads.
        expect(trim($browser->hits()))->toBe('GET '.InstallerBrowser::LATEST_MANIFEST);
    } finally {
        $browser->stop();
    }
});
