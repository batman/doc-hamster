<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Text recognition without root (point 18): the system tools (tesseract +
 * ocrmypdf on the PATH) win; otherwise an app-owned portable Tesseract in
 * storage/app/ocr (A17); otherwise tesseract.js in the browser (A16), shipped
 * in public/vendor/tesseract so it works right after an update (A64/A72).
 * Every download is pinned and checked against a hard-coded SHA-256.
 */
final class LocalOcr
{
    public const string STATUS_KEY = 'ocr.local.status';

    /** v2: the browser files ship with the app now, an older cached "none" must not stay (A64). */
    public const string MODE_KEY = 'ocr.mode.v2';

    /** Why the browser engine could not start (A61), reported by the browser. */
    private const string BROWSER_ERROR_KEY = 'ocr.browser.error';

    /** A set-up still "running" after this long died with its request or worker (A66). */
    private const int RUNNING_MAX_SECONDS = 600;

    /** Browsers can't decode these; the server converts them later. */
    public const array BROWSER_SKIP_MIMES = ['image/heic', 'image/heif'];

    /** Static AppImage (glibc), extracted with --appimage-extract so no FUSE is needed. */
    private const array APPIMAGE = [
        'https://github.com/AlexanderP/tesseract-appimage/releases/download/v5.5.4/tesseract-5.5.4_lept-1.84-x86_64.AppImage',
        'bcec2ab8eb02d4bcddda94ba5ff3eca262ee962a7ff9168d8482f64b0fb019b7',
    ];

    /** tessdata_fast at tag 4.1.0; used by the server binary and by tesseract.js (gzip off). */
    private const array TESSDATA = [
        'deu.traineddata' => ['https://github.com/tesseract-ocr/tessdata_fast/raw/4.1.0/deu.traineddata', '19d219bbb6672c869d20a9636c6816a81eb9a71796cb93ebe0cb1530e2cdb22d'],
        'eng.traineddata' => ['https://github.com/tesseract-ocr/tessdata_fast/raw/4.1.0/eng.traineddata', '7d4322bd2a7749724879683fc3912cb542f19906c83bcc1a52132556427170b2'],
    ];

    /**
     * tesseract.js 5.1.1 + tesseract.js-core 5.1.1 (lstm, plain and SIMD wasm builds) and the TESSDATA files
     * under lang/, shipped in public/vendor/tesseract (no CDN at runtime, docs/DEPENDENCIES.md).
     */
    private const array BROWSER = [
        'tesseract.min.js',
        'worker.min.js',
        'core/tesseract-core-simd-lstm.wasm.js',
        'core/tesseract-core-lstm.wasm.js',
    ];

    public function __construct(private readonly Doctor $doctor) {}

    public function systemAvailable(): bool
    {
        return $this->doctor->isAvailable(Addon::Ocr);
    }

    public function appAvailable(): bool
    {
        return ($this->status()['state'] ?? null) === 'ok' && is_file($this->binary());
    }

    public function browserReady(): bool
    {
        foreach (array_merge(self::BROWSER, array_map(static fn (string $f): string => 'lang/'.$f, array_keys(self::TESSDATA))) as $file) {
            if (! is_file($this->browserDir().'/'.$file)) {
                return false;
            }
        }

        return true;
    }

    /** system | app | browser | none – cached, it is read on every page. */
    public function mode(): string
    {
        return Cache::remember(self::MODE_KEY, 300, function (): string {
            return match (true) {
                $this->systemAvailable() => 'system',
                $this->appAvailable() => 'app',
                $this->browserReady() => 'browser',
                default => 'none',
            };
        });
    }

    /**
     * @return array{state?: string, reason?: string, started?: int}
     */
    public function status(): array
    {
        $status = Cache::get(self::STATUS_KEY);
        if (! is_array($status)) {
            return [];
        }
        // A66: a set-up that died (killed request or worker) shows the button again after 10 minutes.
        if (($status['state'] ?? null) === 'running' && time() - (int) ($status['started'] ?? 0) > self::RUNNING_MAX_SECONDS) {
            Cache::forget(self::STATUS_KEY);

            return [];
        }

        return $status;
    }

    public function markRunning(): void
    {
        Cache::forever(self::STATUS_KEY, ['state' => 'running', 'started' => time()]);
        Cache::forget(self::MODE_KEY);
    }

    /** A61: the browser engine did not start (timeout, wasm blocked …); shown on the settings page and in the menu. */
    public function reportBrowserError(string $reason): void
    {
        Cache::put(self::BROWSER_ERROR_KEY, mb_substr(trim($reason), 0, 200), now()->addDay());
    }

    public function browserError(): ?string
    {
        $error = Cache::get(self::BROWSER_ERROR_KEY);

        return is_string($error) && $error !== '' ? $error : null;
    }

    public function clearBrowserError(): void
    {
        Cache::forget(self::BROWSER_ERROR_KEY);
    }

    /**
     * Tesseract command with resource limits: `nice -n 19` when present.
     * $app uses the portable binary and its tessdata.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    public function command(array $args, bool $app): array
    {
        $base = $app ? [$this->binary(), '--tessdata-dir', $this->tessdataDir()] : [];

        return [...$this->nice(), ...$base, ...$args];
    }

    /**
     * Wraps any other tool (ocrmypdf) in `nice`.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    public function niced(array $command): array
    {
        return [...$this->nice(), ...$command];
    }

    /**
     * @return array<string, string>
     */
    public function env(bool $app): array
    {
        return $app ? ['OMP_THREAD_LIMIT' => '1', 'TESSDATA_PREFIX' => $this->tessdataDir()] : ['OMP_THREAD_LIMIT' => '1'];
    }

    /**
     * Installs the portable Tesseract (A65: "Erkennung auf dem Server einrichten") and stores the outcome for the
     * settings page. The browser fallback ships with the app and needs nothing here.
     *
     * @param  Closure(string, string, string): void  $fetch  downloads url → target and checks the SHA-256 (lives in a net job, D-020)
     * @return array{state?: string, reason?: string, started?: int}
     */
    public function setUp(Closure $fetch): array
    {
        $lock = Cache::lock('ocr.setup', self::RUNNING_MAX_SECONDS);
        if (! $lock->get()) {
            return $this->status();
        }

        try {
            $status = ['state' => 'ok', 'reason' => ''];
            try {
                $this->installApp($fetch);
            } catch (Throwable $e) {
                $status = ['state' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 200)];
            }
            Cache::forever(self::STATUS_KEY, $status);
            Cache::forget(self::MODE_KEY);

            return $status;
        } finally {
            $lock->release();
        }
    }

    /** @param Closure(string, string, string): void $fetch */
    public function installApp(Closure $fetch): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'x86_64') {
            throw new RuntimeException('Nur für Linux x86_64 ('.PHP_OS_FAMILY.' '.php_uname('m').')');
        }
        if (! function_exists('proc_open')) {
            throw new RuntimeException('proc_open ist gesperrt');
        }

        $dir = $this->dir();
        File::ensureDirectoryExists($this->tessdataDir());
        foreach (self::TESSDATA as $file => [$url, $sha]) {
            // The same files ship for the browser: copied, not downloaded, when they are intact.
            $shipped = $this->browserDir().'/lang/'.$file;
            $target = $this->tessdataDir().'/'.$file;
            if (is_file($shipped) && hash_equals($sha, (string) hash_file('sha256', $shipped))) {
                File::copy($shipped, $target);
            } else {
                $fetch($url, $sha, $target);
            }
        }

        $image = $dir.'/tesseract.AppImage';
        $fetch(self::APPIMAGE[0], self::APPIMAGE[1], $image);
        if (! @chmod($image, 0755)) {
            throw new RuntimeException('chmod fehlgeschlagen');
        }
        File::deleteDirectory($dir.'/squashfs-root');
        $extract = new Process([$image, '--appimage-extract'], $dir, null, null, 300);
        $extract->run();
        @unlink($image);
        if (! $extract->isSuccessful() || ! is_file($this->binary())) {
            throw new RuntimeException('Entpacken fehlgeschlagen: '.trim($extract->getErrorOutput() ?: $extract->getOutput()));
        }

        $version = new Process($this->command(['--version'], true), null, $this->env(true), null, 60);
        $version->run();
        if (! $version->isSuccessful() || ! str_contains(strtolower($version->getOutput().$version->getErrorOutput()), 'tesseract')) {
            throw new RuntimeException('Testlauf fehlgeschlagen: '.trim($version->getErrorOutput() ?: $version->getOutput()));
        }
        $langs = new Process($this->command(['--list-langs'], true), null, $this->env(true), null, 60);
        $langs->run();
        if (! str_contains($langs->getOutput().$langs->getErrorOutput(), 'deu')) {
            throw new RuntimeException('Sprachdaten nicht gefunden');
        }
    }

    public function dir(): string
    {
        return storage_path('app/ocr');
    }

    public function browserDir(): string
    {
        return public_path('vendor/tesseract');
    }

    private function binary(): string
    {
        return $this->dir().'/squashfs-root/AppRun';
    }

    private function tessdataDir(): string
    {
        return $this->dir().'/tessdata';
    }

    /**
     * @return list<string>
     */
    private function nice(): array
    {
        $nice = (new ExecutableFinder)->find('nice');

        return $nice !== null ? [$nice, '-n', '19'] : [];
    }
}
