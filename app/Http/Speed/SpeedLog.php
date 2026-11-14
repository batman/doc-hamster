<?php

declare(strict_types=1);

namespace App\Http\Speed;

use Illuminate\Support\Carbon;

/**
 * Point 164.7: the server times of the last days per route, for Einstellungen
 * → Geschwindigkeit. One small text file per day (a line per request: route,
 * total ms, db ms, queries) – appending costs no query; a day stops recording
 * at MAX_BYTES, files older than DAYS are removed.
 */
final class SpeedLog
{
    public const int DAYS = 7;

    private const int MAX_BYTES = 4_000_000;

    public function __construct(private readonly ?string $dir = null) {}

    public function record(string $route, float $totalMs, float $dbMs, int $queries, ?Carbon $at = null): void
    {
        $file = $this->file($at ?? now());
        if (! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0775, true) && ! is_dir(dirname($file))) {
            return;
        }
        if (is_file($file) && (int) filesize($file) > self::MAX_BYTES) {
            return;
        }
        $route = str_replace(["\t", "\n"], ' ', $route);
        @file_put_contents($file, sprintf("%s\t%d\t%d\t%d\n", $route, (int) round($totalMs), (int) round($dbMs), $queries), FILE_APPEND | LOCK_EX);
        if (random_int(1, 200) === 1) {
            $this->prune();
        }
    }

    /**
     * Per route over the last DAYS days: count, median and slowest 5 % (ms), mean db ms and queries; slowest first.
     *
     * @return list<array{route: string, count: int, median: int, p95: int, db: int, queries: int}>
     */
    public function summary(): array
    {
        $this->prune();
        /** @var array<string, array{t: list<int>, db: int, q: int}> $by */
        $by = [];
        foreach ($this->files() as $file) {
            $handle = @fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $parts = explode("\t", rtrim($line, "\n"));
                if (count($parts) !== 4) {
                    continue;
                }
                [$route, $total, $db, $queries] = $parts;
                $by[$route] ??= ['t' => [], 'db' => 0, 'q' => 0];
                $by[$route]['t'][] = (int) $total;
                $by[$route]['db'] += (int) $db;
                $by[$route]['q'] += (int) $queries;
            }
            fclose($handle);
        }
        $rows = [];
        foreach ($by as $route => $data) {
            $times = $data['t'];
            sort($times);
            $count = count($times);
            $rows[] = [
                'route' => $route,
                'count' => $count,
                'median' => self::percentile($times, 50),
                'p95' => self::percentile($times, 95),
                'db' => intdiv($data['db'], $count),
                'queries' => intdiv($data['q'], $count),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['median'] <=> $a['median']);

        return $rows;
    }

    /** Removes everything recorded (the "Zurücksetzen" button). */
    public function clear(): void
    {
        foreach (glob($this->dir().'/*.tsv') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** @param list<int> $sorted */
    public static function percentile(array $sorted, int $p): int
    {
        if ($sorted === []) {
            return 0;
        }

        return $sorted[(int) min(count($sorted) - 1, max(0, (int) ceil($p / 100 * count($sorted)) - 1))];
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = [];
        for ($day = 0; $day < self::DAYS; $day++) {
            $file = $this->file(now()->subDays($day));
            if (is_file($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function prune(): void
    {
        $oldest = now()->subDays(self::DAYS)->format('Y-m-d');
        foreach (glob($this->dir().'/*.tsv') ?: [] as $file) {
            if (basename($file, '.tsv') <= $oldest) {
                @unlink($file);
            }
        }
    }

    private function file(Carbon $at): string
    {
        return $this->dir().'/'.$at->format('Y-m-d').'.tsv';
    }

    private function dir(): string
    {
        return $this->dir ?? storage_path('framework/speed');
    }
}
