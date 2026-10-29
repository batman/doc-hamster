<?php

declare(strict_types=1);

namespace App\Setup;

use InvalidArgumentException;
use RuntimeException;

/**
 * Writes settings into `.env` for the setup wizard (D-024): replaces existing
 * keys, appends new ones, keeps everything else. Values are quoted so any
 * password survives phpdotenv's parser; the file is replaced atomically.
 */
final readonly class EnvFile
{
    public function __construct(private string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function isWritable(): bool
    {
        return $this->exists() ? is_writable($this->path) : is_writable(dirname($this->path));
    }

    /**
     * @param  array<string, string>  $values
     */
    public function set(array $values): void
    {
        $content = $this->exists() ? (string) file_get_contents($this->path) : '';

        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new InvalidArgumentException("Invalid .env key: {$key}");
            }

            $line = $key.'='.self::quote($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $content = preg_match($pattern, $content) === 1
                ? (string) preg_replace_callback($pattern, static fn (): string => $line, $content, 1)
                : rtrim($content, "\n").($content === '' ? '' : "\n").$line."\n";
        }

        $temp = $this->path.'.tmp';

        if (file_put_contents($temp, $content, LOCK_EX) === false || ! rename($temp, $this->path)) {
            @unlink($temp);

            throw new RuntimeException('Could not write '.$this->path);
        }
    }

    private static function quote(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException('.env values must be single-line.');
        }

        if (preg_match('/^[A-Za-z0-9_.:\/@+-]*$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
