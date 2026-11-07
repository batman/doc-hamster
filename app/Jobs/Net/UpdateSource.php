<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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
    private const PULLS = 'https://api.github.com/repos/batman/doc-hamster/pulls';
    private const REPO = 'batman/doc-hamster';
    private const PREVIEW_LABEL = 'preview-ready';

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
     * Open, non-draft pull requests from this repository that are marked for preview.
     *
     * @return list<array{number: int, title: string, branch: string, sha: string}>
     */
    public function previewBranches(): array
    {
        return Cache::remember('updates.preview_branches', now()->addMinutes(5), function (): array {
            $pulls = $this->get(self::PULLS.'?state=open&base=main&per_page=100')->json();
            if (! is_array($pulls)) {
                return [];
            }

            $eligible = [];
            foreach ($pulls as $pull) {
                if (! is_array($pull)
                    || ($pull['state'] ?? null) !== 'open'
                    || ($pull['draft'] ?? true)
                    || ($pull['head']['repo']['full_name'] ?? null) !== self::REPO
                    || ! is_int($pull['number'] ?? null)
                    || ! is_string($pull['head']['ref'] ?? null)
                    || ! is_string($pull['head']['sha'] ?? null)
                    || ! $this->hasPreviewLabel($pull['labels'] ?? [])) {
                    continue;
                }

                $eligible[] = [
                    'number' => $pull['number'],
                    'title' => (string) ($pull['title'] ?? ''),
                    'branch' => $pull['head']['ref'],
                    'sha' => $pull['head']['sha'],
                ];
            }

            return $eligible;
        });
    }

    /**
     * Resolve a still-eligible PR to its checksum-protected preview installer.
     *
     * @return array{version: string, php: string, pr: int, commit_sha: string, installer_sha256: string, installer_url: string}
     */
    public function preview(int $number): array
    {
        $pull = $this->get(self::PULLS.'/'.$number)->json();
        if (! is_array($pull)
            || ($pull['state'] ?? null) !== 'open'
            || ($pull['draft'] ?? true)
            || ($pull['base']['ref'] ?? null) !== 'main'
            || ($pull['head']['repo']['full_name'] ?? null) !== self::REPO
            || ! $this->hasPreviewLabel($pull['labels'] ?? [])) {
            throw new RuntimeException('Dieser Branch ist nicht mehr für Vorschauen freigegeben.');
        }

        $sha = $pull['head']['sha'] ?? null;
        if (! is_string($sha) || $sha === '') {
            throw new RuntimeException('Der freigegebene Branch hat keinen gültigen Stand.');
        }

        $tag = 'preview-pr-'.$number;
        $release = $this->get('https://api.github.com/repos/'.self::REPO.'/releases/tags/'.rawurlencode($tag))->json();
        if (! is_array($release) || ! empty($release['draft']) || empty($release['prerelease'])) {
            throw new RuntimeException('Das Vorschaupaket wird noch gebaut oder ist nicht verfügbar.');
        }

        $assets = array_column(is_array($release['assets'] ?? null) ? $release['assets'] : [], 'browser_download_url', 'name');
        if (! isset($assets['manifest.json'], $assets['install.php'])) {
            throw new RuntimeException('Das Vorschaupaket ist unvollständig.');
        }

        $manifest = $this->get((string) $assets['manifest.json'])->json();
        if (! is_array($manifest)
            || ($manifest['pr'] ?? null) !== $number
            || ! hash_equals($sha, (string) ($manifest['commit_sha'] ?? ''))
            || ! isset($manifest['version'], $manifest['php'], $manifest['installer_sha256'])) {
            throw new RuntimeException('Das Vorschaupaket passt nicht mehr zum freigegebenen Branch.');
        }

        return [
            'version' => (string) $manifest['version'],
            'php' => (string) $manifest['php'],
            'pr' => $number,
            'commit_sha' => $sha,
            'installer_sha256' => (string) $manifest['installer_sha256'],
            'installer_url' => (string) $assets['install.php'],
        ];
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

    private function hasPreviewLabel(mixed $labels): bool
    {
        if (! is_array($labels)) {
            return false;
        }

        foreach ($labels as $label) {
            if (is_array($label) && ($label['name'] ?? null) === self::PREVIEW_LABEL) {
                return true;
            }
        }

        return false;
    }
}
