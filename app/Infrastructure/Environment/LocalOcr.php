<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

use App\Infrastructure\Extensions\Catalogue;
use App\Infrastructure\Extensions\Extension;
use App\Infrastructure\Extensions\Extensions;
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
 * Point 69 (A113): next to the portable Tesseract, the Xpdf command line tools
 * (pdftopng, pdftotext) let the server read PDFs without ocrmypdf.
 * Point 171: more languages come as data packs (Einstellungen → Erweiterungen, Catalogue group 'ocr') into
 * storage/app/ocr/tessdata; OCR runs with all installed and enabled ones together (deu+eng+…), server and browser.
 */
final class LocalOcr
{
    public const string STATUS_KEY = 'ocr.local.status';

    /** v2: the browser files ship with the app now, an older cached "none" must not stay (A64). */
    public const string MODE_KEY = 'ocr.mode.v2';

    /** Why the browser engine could not start (A61), reported by the browser. */
    public const string BROWSER_ERROR_KEY = 'ocr.browser.error';

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
     * A113: Xpdf command line tools 4.06 for Linux (Glyph & Cog, xpdfreader.com, GPL; signature checked against the
     * publisher's key when pinned). Only bin64/pdftopng and bin64/pdftotext are unpacked; they need glibc,
     * libstdc++ and fontconfig, which the test run checks. The tarball moves away on the next Xpdf release –
     * then the download fails and PDFs simply stay with the browser until this pin is updated.
     */
    private const array XPDF = [
        'https://dl.xpdfreader.com/xpdf-tools-linux-4.06.tar.gz',
        '940c137cbd1856e45813da1a25c12e8dd3dc8997b8ea735feb858684603031e3',
        'xpdf-tools-linux-4.06/bin64/',
    ];

    /** 173.4: the languages the core ships; everything else is an extension (171). */
    public const array CORE_LANGUAGES = ['deu', 'eng'];

    /** A113: PDFs longer than this are read up to this page. */
    public const int PDF_MAX_PAGES = 50;

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

    /** A113: the app-owned PDF converter (Xpdf pdftopng + pdftotext) is installed and ran its test. */
    public function pdfAvailable(): bool
    {
        return ($this->status()['pdf'] ?? null) === 'ok' && is_file($this->pdfTool('pdftopng')) && is_file($this->pdfTool('pdftotext'));
    }

    /** The server reads PDFs: ocrmypdf (system) or the app-owned converter next to a working Tesseract. */
    public function serverReadsPdf(): bool
    {
        return $this->systemAvailable() || ($this->appAvailable() && $this->pdfAvailable());
    }

    /**
     * Point 69: the browser loop runs in mode browser, and in mode app while the server cannot read PDFs
     * (the browser then reads only the PDFs). Cached with the mode.
     */
    public function browserLoop(): bool
    {
        $mode = $this->mode();

        return $mode === 'browser' || ($mode === 'app' && $this->browserReady() && ! $this->pdfAvailable());
    }

    /** A114: pdftotext for the text layer – the app-owned one, else a system one (poppler-utils), else none. */
    public function pdfTextTool(): ?string
    {
        if ($this->pdfAvailable()) {
            return $this->pdfTool('pdftotext');
        }

        return $this->systemAvailable() ? (new ExecutableFinder)->find('pdftotext') : null;
    }

    /** Full path of an app-owned Xpdf tool (pdftopng, pdftotext). */
    public function pdfTool(string $name): string
    {
        return $this->dir().'/xpdf/'.$name;
    }

    /** system | app | browser | none – cached, it is read on every page. */
    public function mode(): string
    {
        // Cache::memo(): read once per request (the layout preloads it with the other keys, point 164.4).
        return Cache::memo()->remember(self::MODE_KEY, 300, function (): string {
            return match (true) {
                $this->systemAvailable() => 'system',
                $this->appAvailable() => 'app',
                $this->browserReady() => 'browser',
                default => 'none',
            };
        });
    }

    /**
     * @return array{state?: string, reason?: string, started?: int, pdf?: string, pdf_reason?: string}
     */
    public function status(): array
    {
        $status = Cache::memo()->get(self::STATUS_KEY);
        if (! is_array($status)) {
            return [];
        }
        // A66: a set-up that died (killed request or worker) shows the button again after 10 minutes.
        if (($status['state'] ?? null) === 'running' && time() - (int) ($status['started'] ?? 0) > self::RUNNING_MAX_SECONDS) {
            Cache::memo()->forget(self::STATUS_KEY);

            return [];
        }

        return $status;
    }

    public function markRunning(): void
    {
        Cache::memo()->forever(self::STATUS_KEY, ['state' => 'running', 'started' => time()]);
        Cache::memo()->forget(self::MODE_KEY);
    }

    /** A61: the browser engine did not start (timeout, wasm blocked …); shown on the settings page and in the menu. */
    public function reportBrowserError(string $reason): void
    {
        Cache::memo()->put(self::BROWSER_ERROR_KEY, mb_substr(trim($reason), 0, 200), now()->addDay());
    }

    public function browserError(): ?string
    {
        $error = Cache::memo()->get(self::BROWSER_ERROR_KEY);

        return is_string($error) && $error !== '' ? $error : null;
    }

    public function clearBrowserError(): void
    {
        Cache::memo()->forget(self::BROWSER_ERROR_KEY);
    }

    /**
     * Tesseract command with resource limits: `nice -n 19` when present.
     * $app uses the portable binary and its tessdata; $appData makes the system tesseract read the app's tessdata
     * (171.1: the extra languages live there).
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    public function command(array $args, bool $app, bool $appData = false): array
    {
        $base = $app ? [$this->binary(), '--tessdata-dir', $this->tessdataDir()]
            : ['tesseract', ...($appData ? ['--tessdata-dir', $this->tessdataDir()] : [])];

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
    public function env(bool $app, bool $appData = false): array
    {
        return $app || $appData ? ['OMP_THREAD_LIMIT' => '1', 'TESSDATA_PREFIX' => $this->tessdataDir()] : ['OMP_THREAD_LIMIT' => '1'];
    }

    /**
     * 171.2: the languages OCR runs with – deu+eng plus every installed and enabled OCR language pack. A345: a
     * document's own choice ("ell" or "deu+ell") replaces them when all its languages are installed.
     *
     * @return list<string>
     */
    public function languages(?string $choice = null): array
    {
        return self::pickLanguages($this->installedLanguages(), $choice, $this->installedLanguages(false));
    }

    /**
     * deu+eng plus the installed OCR language packs (only the switched-on ones when $enabledOnly).
     *
     * @return list<string>
     */
    public function installedLanguages(bool $enabledOnly = true): array
    {
        $extensions = app(Extensions::class);
        $packs = $enabledOnly ? $extensions->enabled('ocr')
            : array_filter(Catalogue::group('ocr'), static fn (Extension $e): bool => $extensions->isInstalled($e->key));
        $codes = array_map(static fn (Extension $e): string => $e->meta['lang'] ?? '', $packs);

        return array_values(array_unique([...self::CORE_LANGUAGES, ...array_filter($codes, static fn (string $c): bool => preg_match('/^[a-z_]{3,10}$/', $c) === 1)]));
    }

    /**
     * The document's choice when every language of it is installed, else the enabled ones.
     *
     * @param  list<string>  $enabled
     * @param  list<string>  $installed
     * @return list<string>
     */
    public static function pickLanguages(array $enabled, ?string $choice, array $installed): array
    {
        if ($choice !== null && $choice !== '') {
            $parts = array_values(array_unique(explode('+', $choice)));
            if (array_diff($parts, $installed) === []) {
                return $parts;
            }
        }

        return $enabled;
    }

    /**
     * 171.1: the languages the server tesseract can really use and whether it must read the app's tessdata. The
     * app binary always does; a system tesseract only for extra languages, when the app dir holds all of them
     * (deu/eng are copied there with the first pack, ensureCoreTessdata()) – otherwise it falls back to deu+eng.
     *
     * @param  list<string>  $languages
     * @return array{0: list<string>, 1: bool} languages, read the app's tessdata
     */
    public function serverLanguages(array $languages, bool $app): array
    {
        $present = array_values(array_filter($languages, fn (string $l): bool => is_file($this->tessdataDir().'/'.$l.'.traineddata')));
        if ($app) {
            return [$present !== [] ? $present : self::CORE_LANGUAGES, false];
        }
        if (array_diff($languages, self::CORE_LANGUAGES) === []) {
            return [$languages, false];
        }
        if (count($present) === count($languages)) {
            return [$languages, true];
        }
        $core = array_values(array_intersect($languages, self::CORE_LANGUAGES));

        return [$core !== [] ? $core : self::CORE_LANGUAGES, false];
    }

    /** 171.1: the traineddata file of an installed language (served to tesseract.js), or null. */
    public function languageFile(string $code): ?string
    {
        if (! in_array($code, $this->installedLanguages(false), true)) {
            return null;
        }
        foreach ([$this->browserDir().'/lang/'.$code.'.traineddata', $this->tessdataDir().'/'.$code.'.traineddata'] as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }

    /** 171.1: the shipped deu/eng next to the extra languages, so a system tesseract reads them all from one dir. */
    public function ensureCoreTessdata(): void
    {
        File::ensureDirectoryExists($this->tessdataDir());
        foreach (self::TESSDATA as $file => [, $sha]) {
            $shipped = $this->browserDir().'/lang/'.$file;
            $target = $this->tessdataDir().'/'.$file;
            if (! is_file($target) && is_file($shipped) && hash_equals($sha, (string) hash_file('sha256', $shipped))) {
                File::copy($shipped, $target);
            }
        }
    }

    /**
     * Installs the portable Tesseract (A65: "Erkennung auf dem Server einrichten") and stores the outcome for the
     * settings page. The browser fallback ships with the app and needs nothing here.
     *
     * @param  Closure(string, string, string): void  $fetch  downloads url → target and checks the SHA-256 (lives in a net job, D-020)
     *                                                        A113: the PDF converter comes along; when it fails the Tesseract set-up still counts, PDFs stay with the browser.
     * @return array{state?: string, reason?: string, started?: int, pdf?: string, pdf_reason?: string}
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
                // A Tesseract that already works is kept; only the converter is added.
                if (! $this->appAvailable()) {
                    $this->installApp($fetch);
                }
            } catch (Throwable $e) {
                $status = ['state' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 200)];
            }
            if ($status['state'] === 'ok') {
                try {
                    $this->installPdf($fetch);
                    $status += ['pdf' => 'ok'];
                } catch (Throwable $e) {
                    $status += ['pdf' => 'failed', 'pdf_reason' => mb_substr($e->getMessage(), 0, 200)];
                }
            }
            Cache::memo()->forever(self::STATUS_KEY, $status);
            Cache::memo()->forget(self::MODE_KEY);

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

    /**
     * A113: unpacks pdftopng and pdftotext from the pinned Xpdf tarball into storage/app/ocr/xpdf and test-runs them.
     *
     * @param  Closure(string, string, string): void  $fetch
     */
    public function installPdf(Closure $fetch): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || php_uname('m') !== 'x86_64') {
            throw new RuntimeException('Nur für Linux x86_64');
        }
        $target = $this->dir().'/xpdf';
        File::ensureDirectoryExists($target);
        $archive = $this->dir().'/xpdf-tools.tar.gz';
        $fetch(self::XPDF[0], self::XPDF[1], $archive);
        try {
            foreach (['pdftopng', 'pdftotext'] as $tool) {
                $unpack = new Process(['tar', '-xzf', $archive, '-O', self::XPDF[2].$tool], null, null, null, 120);
                $unpack->run();
                if (! $unpack->isSuccessful() || $unpack->getOutput() === '') {
                    throw new RuntimeException('Entpacken fehlgeschlagen: '.trim($unpack->getErrorOutput()));
                }
                if (file_put_contents($this->pdfTool($tool), $unpack->getOutput()) === false || ! @chmod($this->pdfTool($tool), 0755)) {
                    throw new RuntimeException('Schreiben fehlgeschlagen: '.$tool);
                }
                // Xpdf prints its version on stderr; a missing library shows up here.
                $test = new Process([$this->pdfTool($tool), '-v'], null, null, null, 60);
                $test->run();
                if (! str_contains(strtolower($test->getOutput().$test->getErrorOutput()), $tool)) {
                    throw new RuntimeException('Testlauf '.$tool.' fehlgeschlagen: '.mb_substr(trim($test->getErrorOutput() ?: $test->getOutput()), 0, 150));
                }
            }
        } finally {
            @unlink($archive);
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

    public function tessdataDir(): string
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
