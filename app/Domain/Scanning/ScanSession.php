<?php

declare(strict_types=1);

namespace App\Domain\Scanning;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The pages of one document in the making: stored one JPEG per page in a
 * private folder, until "Fertig" turns them into a single PDF. One folder per
 * person and organisation; nothing here is reachable from the web.
 */
final class ScanSession
{
    private const DISK = 'local';

    public function __construct(private readonly string $orgId, private readonly string $userId) {}

    /** @return list<string> file names, in page order */
    public function pages(): array
    {
        $files = Storage::disk(self::DISK)->files($this->dir());
        $names = array_map(static fn (string $path): string => basename($path), $files);
        $names = array_values(array_filter($names, static fn (string $name): bool => preg_match('/^\d{4}\.jpg$/', $name) === 1));
        sort($names);

        return $names;
    }

    public function count(): int
    {
        return count($this->pages());
    }

    /** @param  list<string>  $jpegs */
    public function add(array $jpegs): void
    {
        $next = $this->count();
        foreach ($jpegs as $jpeg) {
            $next++;
            Storage::disk(self::DISK)->put($this->dir().'/'.sprintf('%04d.jpg', $next), $jpeg);
        }
    }

    /** One page's bytes; the number is 1-based. */
    public function page(int $number): ?string
    {
        $name = $this->pages()[$number - 1] ?? null;

        return $name === null ? null : Storage::disk(self::DISK)->get($this->dir().'/'.$name);
    }

    public function remove(int $number): void
    {
        $name = $this->pages()[$number - 1] ?? null;
        if ($name === null) {
            return;
        }
        Storage::disk(self::DISK)->delete($this->dir().'/'.$name);
        // Close the gap so the numbers stay 1..n.
        foreach ($this->pages() as $index => $existing) {
            $wanted = sprintf('%04d.jpg', $index + 1);
            if ($existing !== $wanted) {
                Storage::disk(self::DISK)->move($this->dir().'/'.$existing, $this->dir().'/'.$wanted);
            }
        }
    }

    public function discard(): void
    {
        Storage::disk(self::DISK)->deleteDirectory($this->dir());
    }

    /** All pages as one PDF; null when there are none. */
    public function toPdf(int $dpi): ?string
    {
        $jpegs = [];
        foreach ($this->pages() as $name) {
            $jpegs[] = (string) Storage::disk(self::DISK)->get($this->dir().'/'.$name);
        }

        return $jpegs === [] ? null : PdfBuilder::fromJpegs($jpegs, $dpi);
    }

    private function dir(): string
    {
        // Ids come from the session, not from a request; still, keep them to safe characters.
        return 'scans/'.Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $this->orgId) ?? '').'/'.Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $this->userId) ?? '');
    }
}
