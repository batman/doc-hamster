<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * Proves that the person in the browser controls the server (D-024): before
 * setup, anyone who finds the address could open the wizard. The code is
 * written to a file outside the web root; the installer reads it with the
 * same FTP/file manager they uploaded DocHamster with.
 */
final readonly class SetupCode
{
    public function __construct(private string $path) {}

    /** Path relative to the installation, as shown in the wizard. */
    public function displayPath(): string
    {
        $path = str_starts_with($this->path, base_path())
            ? ltrim(substr($this->path, strlen(base_path())), '\\/')
            : $this->path;

        return str_replace('\\', '/', $path);
    }

    /**
     * Creates the code file on first use; false if storage isn't writable.
     */
    public function ensure(): bool
    {
        if (is_file($this->path)) {
            return true;
        }

        $dir = dirname($this->path);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            return false;
        }

        // 20 characters from an alphabet without look-alikes (0/O, 1/l/I), in groups of 5.
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 20; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return @file_put_contents($this->path, implode('-', str_split($code, 5))."\n", LOCK_EX) !== false;
    }

    public function matches(string $input): bool
    {
        $stored = is_file($this->path) ? self::normalise((string) file_get_contents($this->path)) : '';

        return $stored !== '' && hash_equals($stored, self::normalise($input));
    }

    public function delete(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    private static function normalise(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
