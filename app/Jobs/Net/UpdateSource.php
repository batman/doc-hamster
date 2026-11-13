<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads DocHamster releases from GitHub release downloads. The only outbound call besides mail
 * and bank modules (D-019/D-020): it runs solely when an owner clicks, never
 * in the background, and synchronously, because cron may not be set up yet.
 * Nothing about the instance is sent.
 */
final class UpdateSource
{
    /*
     * Only plain file downloads from release assets, never the GitHub API: the API allows an
     * anonymous server 60 calls an hour, downloads have no such limit (A87, 2026-11-08).
     */
    private const DOWNLOADS = 'https://github.com/batman/doc-hamster/releases';

    /** The list of branches open for preview, kept current by .github/workflows/preview.yml. */
    private const PREVIEW_LIST = self::DOWNLOADS.'/download/previews/previews.json';

    /**
     * The newest release, if it runs on this PHP version and can update itself.
     *
     * @return array{version: string, date: string, installer_sha256: string, installer_url: string}|null
     */
    public function latest(string $phpVersion): ?array
    {
        $manifest = $this->get(self::DOWNLOADS.'/latest/download/manifest.json')->json();
        if (! is_array($manifest) || ! isset($manifest['version'], $manifest['php'], $manifest['installer_sha256'])
            || ! version_compare($phpVersion, (string) $manifest['php'], '>=')) {
            return null;
        }

        return [
            'version' => (string) $manifest['version'],
            'date' => self::day($manifest['date'] ?? null),
            'installer_sha256' => (string) $manifest['installer_sha256'],
            'installer_url' => self::DOWNLOADS.'/download/v'.rawurlencode((string) $manifest['version']).'/install.php',
        ];
    }

    /**
     * Open pull requests from this repository that are marked for preview and have a built package.
     *
     * @return list<array{number: int, title: string, branch: string, sha: string, date: string}>
     */
    public function previewBranches(): array
    {
        $response = $this->client()->get(self::PREVIEW_LIST);
        if ($response->status() === 404) {
            return [];
        }
        $list = $response->throw()->json();

        $eligible = [];
        foreach (is_array($list) ? $list : [] as $entry) {
            if (! is_array($entry)
                || ! is_int($entry['number'] ?? null)
                || ! is_string($entry['branch'] ?? null)
                || ! is_string($entry['sha'] ?? null)) {
                continue;
            }

            $eligible[] = [
                'number' => $entry['number'],
                'title' => (string) ($entry['title'] ?? ''),
                'branch' => $entry['branch'],
                'sha' => $entry['sha'],
                'date' => self::day($entry['date'] ?? null),
            ];
        }

        return $eligible;
    }

    /**
     * Resolve a still-listed PR to its checksum-protected preview installer.
     *
     * @return array{version: string, php: string, pr: int, commit_sha: string, installer_sha256: string, installer_url: string}
     */
    public function preview(int $number): array
    {
        $listed = collect($this->previewBranches())->firstWhere('number', $number);
        if (! is_array($listed)) {
            throw new RuntimeException('Dieser Branch ist nicht mehr für Vorschauen freigegeben.');
        }

        $base = self::DOWNLOADS.'/download/preview-pr-'.$number;
        $manifest = $this->get($base.'/manifest.json')->json();
        if (! is_array($manifest)
            || ($manifest['pr'] ?? null) !== $number
            || ! hash_equals($listed['sha'], (string) ($manifest['commit_sha'] ?? ''))
            || ! isset($manifest['version'], $manifest['php'], $manifest['installer_sha256'])) {
            throw new RuntimeException('Das Vorschaupaket passt nicht mehr zum freigegebenen Branch.');
        }

        return [
            'version' => (string) $manifest['version'],
            'php' => (string) $manifest['php'],
            'pr' => $number,
            'commit_sha' => $listed['sha'],
            'installer_sha256' => (string) $manifest['installer_sha256'],
            'installer_url' => $base.'/install.php',
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

    /** The day of a build as Y-m-d, or '' when the list or manifest has none (older builds). */
    private static function day(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m) === 1 ? $m[0] : '';
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
