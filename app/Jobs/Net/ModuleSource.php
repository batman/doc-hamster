<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use App\Modules\Manifest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Where modules come from (D-041): a GitHub project whose newest release carries two files – the Steckbrief
 * dochamster-module.json (with zip_sha256) and module.zip – and the public partner list in docHamster's own project.
 * Only ever on a click (like the update check); the only place that fetches anything for modules (D-020).
 */
final class ModuleSource
{
    /** The public list of partner projects (F20): a JSON list of "owner/repo". */
    public const PARTNERS = 'https://raw.githubusercontent.com/batman/doc-hamster/main/modules/partners.json';

    public const MAX_ZIP = 50 * 1024 * 1024;

    /**
     * "https://github.com/owner/repo", "github.com/owner/repo" or "owner/repo" → "owner/repo"; null if it is none of them.
     * "owner/repo#tag" names one release of a project that publishes several packages (e.g. docHamster's own modules).
     */
    public static function repo(string $address): ?string
    {
        $address = trim($address);
        $tag = null;
        if (preg_match('~#([A-Za-z0-9._-]{1,100})$~', $address, $m) === 1) {
            $tag = $m[1];
            $address = substr($address, 0, -strlen($m[0]));
        }
        $address = (string) preg_replace('~^(https?://)?(www\.)?github\.com/~i', '', $address);
        $address = rtrim((string) preg_replace('~(\.git)?/?$~', '', $address), '/');

        return preg_match('~^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9._-]{1,100}$~', $address) === 1 ? $address.($tag !== null ? '#'.$tag : '') : null;
    }

    /** Where the two files of a module lie: the newest release, or the named one ("owner/repo#tag"). */
    private static function release(string $repo): string
    {
        [$project, $tag] = array_pad(explode('#', $repo, 2), 2, null);

        return 'https://github.com/'.$project.'/releases/'.($tag !== null ? 'download/'.$tag : 'latest/download');
    }

    /**
     * The Steckbrief of the newest release of a project.
     *
     * @throws RuntimeException with a German reason
     */
    public function find(string $repo): Manifest
    {
        $response = $this->get(self::release($repo).'/dochamster-module.json');
        $data = $response->json();
        try {
            $manifest = Manifest::from(is_array($data) ? $data : []);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }
        if ($manifest->zipSha256 === null) {
            throw new RuntimeException(__('modules.bad_manifest', ['field' => 'zip_sha256']));
        }

        return $manifest;
    }

    /**
     * The partner projects with their modules; a project that does not answer is left out.
     *
     * @return list<array{source: string, manifest: Manifest}>
     */
    public function partners(): array
    {
        $list = $this->get(self::PARTNERS)->json();
        $found = [];
        foreach (array_slice(is_array($list) ? $list : [], 0, 30) as $entry) {
            $repo = is_string($entry) ? self::repo($entry) : null;
            if ($repo === null) {
                continue;
            }
            try {
                $found[] = ['source' => $repo, 'manifest' => $this->find($repo)];
            } catch (RuntimeException) {
                continue;
            }
        }

        return $found;
    }

    /** Downloads module.zip of the newest release and checks it against the Steckbrief's checksum. */
    public function download(string $repo, Manifest $manifest, string $target): void
    {
        $tmp = $target.'.part';
        try {
            Http::withUserAgent('DocHamster')->timeout(300)->withOptions(['sink' => $tmp])
                ->get(self::release($repo).'/module.zip')->throw();
            if (filesize($tmp) > self::MAX_ZIP || ! hash_equals((string) $manifest->zipSha256, (string) hash_file('sha256', $tmp))) {
                throw new RuntimeException(__('modules.checksum'));
            }
            rename($tmp, $target);
        } catch (ConnectionException|RequestException $e) {
            throw new RuntimeException(__('modules.unreachable'), previous: $e);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function get(string $url): Response
    {
        try {
            return Http::withUserAgent('DocHamster')->timeout(20)->get($url)->throw();
        } catch (ConnectionException|RequestException $e) {
            throw new RuntimeException(__('modules.unreachable'), previous: $e);
        }
    }
}
