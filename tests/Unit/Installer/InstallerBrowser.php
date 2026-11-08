<?php

declare(strict_types=1);

namespace Tests\Unit\Installer;

/**
 * Drives a copy of the standalone installer over HTTP with a cookie jar.
 *
 * The copy rewrites the GitHub API base to a fixture API server, so release
 * lookups never touch the network. Two php -S processes are required: the
 * built-in server is single-threaded, so the installer could never reach a
 * fixture API served by its own process. Everything lives under one unique
 * parent dir for single-call cleanup; API hits are logged for hammering
 * assertions.
 *
 * @phpstan-type RouteResponse array{status: int, body: string, headers?: list<string>}
 * @phpstan-type HttpResponse array{0: int, 1: list<string>, 2: string}
 */
final class InstallerBrowser
{
    public const LATEST_MANIFEST = '/api/gh/batman/doc-hamster/releases/latest/download/manifest.json';

    private mixed $serverProc = null;

    private mixed $apiProc = null;

    /** @var array<string, string> */
    private array $cookies = [];

    private function __construct(
        public readonly string $parent,
        public readonly string $webRoot,
        public readonly string $baseUrl,
        public readonly string $apiRoot,
        public readonly string $apiBase,
    ) {}

    /**
     * Starts the installer and fixture API servers; $routes maps 'METHOD
     * /path-prefix' to a response, $files (relative path => content) lands in
     * the API docroot. Both accept a {BASE} placeholder for the API URL,
     * replaced once the port is known.
     *
     * @param  array<string, RouteResponse>  $routes
     * @param  array<string, string>  $files
     */
    public static function start(array $routes, array $files = []): self
    {
        $parent = InstallerFixtures::makeTempDir('dh-browser');
        $webRoot = $parent.'/web';
        $apiRoot = $parent.'/api';
        mkdir($webRoot, 0777, true);
        mkdir($apiRoot, 0777, true);
        file_put_contents($apiRoot.'/router.php', self::routerCode());
        file_put_contents($apiRoot.'/hits.log', '');
        // The router runs during the boot probes below, before the real routes exist.
        file_put_contents($apiRoot.'/routes.json', '[]');

        // Polling hits refused connections until a server is up; swallow those.
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            [$apiProc, $apiBase] = self::bootServer($apiRoot, $apiRoot.'/router.php', 19831, 19871);
            try {
                file_put_contents($apiRoot.'/routes.json', str_replace('{BASE}', $apiBase, (string) json_encode($routes)));
                foreach ($files as $name => $content) {
                    $path = $apiRoot.'/'.$name;
                    $dir = dirname($path);
                    if (! is_dir($dir)) {
                        mkdir($dir, 0777, true);
                    }
                    file_put_contents($path, str_replace('{BASE}', $apiBase, $content));
                }
                // The installer copy goes live only after its API base is rewritten.
                $installer = (string) file_get_contents(dirname(__DIR__, 3).'/installer/install.php');
                file_put_contents($webRoot.'/install.php', str_replace('https://github.com', $apiBase.'/api/gh', $installer));
                [$webProc, $webBase] = self::bootServer($webRoot, null, 19931, 19971);
            } catch (\Throwable $e) {
                InstallerFixtures::stopServer($apiProc);

                throw $e;
            }
        } catch (\Throwable $e) {
            restore_error_handler();
            InstallerFixtures::removeDir($parent);

            throw $e;
        }
        restore_error_handler();

        $browser = new self($parent, $webRoot, $webBase, $apiRoot, $apiBase);
        $browser->serverProc = $webProc;
        $browser->apiProc = $apiProc;

        return $browser;
    }

    /**
     * Routes for GitHub offering exactly one fitting release (the latest manifest download).
     *
     * @return array{0: array<string, RouteResponse>, 1: array<string, string>}
     */
    public static function releaseRoutes(string $version, string $php, string $sha256): array
    {
        $manifest = ['version' => $version, 'php' => $php, 'zip' => 'dochamster-'.$version.'.zip', 'sha256' => $sha256];

        return [
            ['GET '.self::LATEST_MANIFEST => ['status' => 200, 'body' => (string) json_encode($manifest)]],
            [],
        ];
    }

    /** Where the fixture server serves a release zip, as the installer asks for it. */
    public static function zipPath(string $version): string
    {
        return 'api/gh/batman/doc-hamster/releases/download/v'.$version.'/dochamster-'.$version.'.zip';
    }

    /**
     * @param  list<string>  $headers
     * @return HttpResponse [status, headers, body]
     */
    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    /**
     * @param  array<string, string>  $data
     * @param  list<string>  $headers
     * @return HttpResponse [status, headers, body]
     */
    public function post(string $path, array $data, array $headers = []): array
    {
        return $this->request('POST', $path, http_build_query($data), $headers);
    }

    /**
     * Runs the welcome code step and returns the server-check page body.
     *
     * @param  list<string>  $headers  extra headers (e.g. an HTTPS proxy marker) on every request
     */
    public function unlock(array $headers = []): string
    {
        [, , $welcome] = $this->get('/install.php', $headers);
        if (preg_match('/name="csrf" value="([^"]+)"/', $welcome, $m) !== 1) {
            throw new \RuntimeException('Welcome page has no CSRF field.');
        }
        $code = trim((string) file_get_contents($this->parent.'/dochamster-install-code.txt'));
        [$status, $responseHeaders] = $this->post('/install.php', ['csrf' => $m[1], 'code' => $code], $headers);
        if ($status !== 302) {
            throw new \RuntimeException('Unlock did not redirect, status '.$status.'.');
        }
        $location = 'install.php';
        foreach ($responseHeaders as $header) {
            if (preg_match('/^Location:\s*(\S+)/i', $header, $m) === 1) {
                $location = trim($m[1]);
            }
        }
        [, , $checks] = $this->get('/'.ltrim($location, '/'), $headers);

        return $checks;
    }

    /** Logged API hits ('METHOD /path' lines) since start. */
    public function hits(): string
    {
        return (string) file_get_contents($this->apiRoot.'/hits.log');
    }

    public function stop(): void
    {
        InstallerFixtures::stopServer($this->serverProc);
        InstallerFixtures::stopServer($this->apiProc);
        $this->serverProc = null;
        $this->apiProc = null;
        InstallerFixtures::removeDir($this->parent);
    }

    /**
     * Serves $docRoot (via $router when given) on a free loopback port.
     * The caller holds the error handler and cleans up on failure.
     *
     * @return array{0: mixed, 1: string} [process, baseUrl]
     */
    private static function bootServer(string $docRoot, ?string $router, int $firstPort, int $lastPort): array
    {
        $token = bin2hex(random_bytes(8));
        file_put_contents($docRoot.'/.dh-server-check', $token);
        $context = stream_context_create(['http' => ['timeout' => 2]]);

        foreach (range($firstPort, $lastPort) as $port) {
            $command = [PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $docRoot];
            if ($router !== null) {
                $command[] = $router;
            }
            $proc = proc_open(
                $command,
                [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
                $pipes
            );
            if (! is_resource($proc)) {
                continue;
            }
            $baseUrl = 'http://127.0.0.1:'.$port;
            for ($i = 0; $i < 50; $i++) {
                if (file_get_contents($baseUrl.'/.dh-server-check', false, $context) === $token) {
                    return [$proc, $baseUrl];
                }
                usleep(100000);
            }
            InstallerFixtures::stopServer($proc);
        }

        throw new \RuntimeException('No free port for the installer browser server.');
    }

    private static function routerCode(): string
    {
        // ltrim: any byte before <?php would corrupt every response (headers already sent).
        return ltrim(<<<'PHP'
            <?php
            $routesFile = __DIR__.'/routes.json';
            $routes = is_file($routesFile) ? json_decode((string) file_get_contents($routesFile), true) : [];
            $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $key = ($_SERVER['REQUEST_METHOD'] ?? 'GET').' '.$path;
            if ($path === '/api' || str_starts_with($path, '/api/')) {
                file_put_contents(__DIR__.'/hits.log', $key."\n", FILE_APPEND);
            }
            foreach (is_array($routes) ? $routes : [] as $prefix => $resp) {
                if (str_starts_with($key, (string) $prefix)) {
                    http_response_code((int) ($resp['status'] ?? 200));
                    foreach ($resp['headers'] ?? [] as $header) {
                        header($header);
                    }
                    echo $resp['body'] ?? '';

                    exit;
                }
            }

            return false;
            PHP);
    }

    /**
     * @param  list<string>  $headers
     * @return HttpResponse
     */
    private function request(string $method, string $path, ?string $body, array $headers): array
    {
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name.'='.$value;
            }
            $headers[] = 'Cookie: '.implode('; ', $pairs);
        }
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => 30,
            'ignore_errors' => true,
            'max_redirects' => 0,
        ]]);
        $responseBody = file_get_contents($this->baseUrl.$path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $m) === 1) {
            $status = (int) $m[1];
        }
        foreach ($responseHeaders as $header) {
            if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $m) === 1) {
                $this->cookies[trim($m[1])] = trim($m[2]);
            }
        }

        return [$status, $responseHeaders, (string) $responseBody];
    }
}
