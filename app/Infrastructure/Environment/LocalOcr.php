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
 * storage/app/ocr (A17); otherwise tesseract.js in the browser (A16).
 * Every download is pinned and checked against a hard-coded SHA-256.
 */
final class LocalOcr
{
    public const string STATUS_KEY = 'ocr.local.status';

    private const string MODE_KEY = 'ocr.mode';

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

    /** tesseract.js 5.1.1 + tesseract.js-core 5.1.1, served from public/vendor/tesseract (no CDN at runtime). */
    private const array BROWSER = [
        'tesseract.min.js' => ['https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js', 'a8e29918d098b2b06e1012bdaeffb4aec0445c5d5654709023e0bd1f442a80e8'],
        'worker.min.js' => ['https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/worker.min.js', 'aca1229639fc9907d86f96e825955a2b7c5716d17f3bc3acd71f9c7ab66181fc'],
        'core/tesseract-core-simd-lstm.wasm.js' => ['https://cdn.jsdelivr.net/npm/tesseract.js-core@5.1.1/tesseract-core-simd-lstm.wasm.js', 'ce20eda9533cbed1e6c2b4276fbae1e0adc61b6754b5513084be601787b457cf'],
        'core/tesseract-core-lstm.wasm.js' => ['https://cdn.jsdelivr.net/npm/tesseract.js-core@5.1.1/tesseract-core-lstm.wasm.js', '8f04aa0cc81e7bde33f80e92fa01a7a665f0b4884d098acf5de9c7104a11dfaa'],
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
        foreach (array_merge(array_keys(self::BROWSER), array_map(static fn (string $f): string => 'lang/'.$f, array_keys(self::TESSDATA))) as $file) {
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
     * @return array{state?: string, reason?: string, browser?: string}
     */
    public function status(): array
    {
        $status = Cache::get(self::STATUS_KEY);

        return is_array($status) ? $status : [];
    }

    public function markRunning(): void
    {
        Cache::forever(self::STATUS_KEY, ['state' => 'running']);
        Cache::forget(self::MODE_KEY);
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
     * Installs the portable Tesseract; if that fails here, fetches the
     * browser fallback instead. Stores the outcome for the settings row.
     *
     * @param  Closure(string, string, string): void  $fetch  downloads url → target and checks the SHA-256 (lives in a net job, D-020)
     * @return array{state?: string, reason?: string, browser?: string}
     */
    public function setUp(Closure $fetch): array
    {
        $lock = Cache::lock('ocr.setup', 900);
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
            if ($status['state'] === 'failed' && ! $this->systemAvailable()) {
                try {
                    $this->installBrowser($fetch);
                    $status['browser'] = 'ok';
                } catch (Throwable $e) {
                    $status['browser'] = mb_substr($e->getMessage(), 0, 200);
                }
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
            $fetch($url, $sha, $this->tessdataDir().'/'.$file);
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

    /** @param Closure(string, string, string): void $fetch */
    public function installBrowser(Closure $fetch): void
    {
        $dir = $this->browserDir();
        foreach (self::BROWSER as $file => [$url, $sha]) {
            File::ensureDirectoryExists(dirname($dir.'/'.$file));
            $fetch($url, $sha, $dir.'/'.$file);
        }
        File::ensureDirectoryExists($dir.'/lang');
        foreach (self::TESSDATA as $file => [$url, $sha]) {
            $local = $this->tessdataDir().'/'.$file;
            if (is_file($local) && hash_equals($sha, (string) hash_file('sha256', $local))) {
                File::copy($local, $dir.'/lang/'.$file);
            } else {
                $fetch($url, $sha, $dir.'/lang/'.$file);
            }
        }
        Cache::forget(self::MODE_KEY);
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
