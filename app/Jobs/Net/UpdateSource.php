<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads DocHamster releases from GitHub. The only outbound call besides mail
 * and bank modules (D-019/D-020): it runs solely when an owner clicks, never
 * in the background, and synchronously, because cron may not be set up yet.
 * Nothing about the instance is sent.
 */
final class UpdateSource
{
    private const RELEASES = 'https://api.github.com/repos/batman/doc-hamster/releases?per_page=20';

    /**
     * Newest release that runs on this PHP version and can update itself.
     *
     * @return array{version: string, installer_sha256: string, installer_url: string}|null
     */
    public function latest(string $phpVersion): ?array
    {
        $releases = $this->get(self::RELEASES)->json();

        foreach (is_array($releases) ? $releases : [] as $release) {
            if (! is_array($release) || ! empty($release['draft']) || ! empty($release['prerelease'])) {
                continue;
            }

            $assets = array_column(is_array($release['assets'] ?? null) ? $release['assets'] : [], 'browser_download_url', 'name');
            if (! isset($assets['manifest.json'], $assets['install.php'])) {
                continue;
            }

            $manifest = $this->get((string) $assets['manifest.json'])->json();
            if (is_array($manifest) && isset($manifest['version'], $manifest['php'], $manifest['installer_sha256'])
                && version_compare($phpVersion, (string) $manifest['php'], '>=')) {
                return [
                    'version' => (string) $manifest['version'],
                    'installer_sha256' => (string) $manifest['installer_sha256'],
                    'installer_url' => (string) $assets['install.php'],
                ];
            }
        }

        return null;
    }

    /**
     * @param  array{installer_sha256: string, installer_url: string}  $release
     */
    public function installer(array $release): string
    {
        $body = $this->get($release['installer_url'])->body();

        if (! hash_equals($release['installer_sha256'], hash('sha256', $body))) {
            throw new RuntimeException('Installer checksum mismatch.');
        }

        return $body;
    }

    private function get(string $url): Response
    {
        try {
            return $this->client()->get($url)->throw();
        } catch (ConnectionException $e) {
            throw new RuntimeException('GitHub is not reachable.', previous: $e);
        }
    }

    private function client(): PendingRequest
    {
        return Http::withUserAgent('DocHamster')->timeout(20);
    }
}
