<?php

declare(strict_types=1);

use App\Http\Speed\SpeedLog;

/*
| Point 164.7: server times per route – median and slowest 5 % over the last 7 days, older days dropped.
*/
beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/dh-speed-'.bin2hex(random_bytes(4));
    $this->log = new SpeedLog($this->dir);
});

afterEach(function (): void {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

it('gives median and slowest 5 % per route, slowest first', function (): void {
    foreach (range(1, 100) as $ms) {
        $this->log->record('documents.index', $ms, 10, 8);
    }
    $this->log->record('settings.index', 300, 1, 2, now()->subDays(2));

    $rows = $this->log->summary();

    expect(array_column($rows, 'route'))->toBe(['settings.index', 'documents.index'])
        ->and($rows[1])->toMatchArray(['count' => 100, 'median' => 50, 'p95' => 95, 'db' => 10, 'queries' => 8]);
});

it('forgets days older than a week', function (): void {
    $this->log->record('home', 40, 1, 1, now()->subDays(SpeedLog::DAYS + 1));
    $this->log->record('home', 20, 1, 1);

    expect($this->log->summary())->toHaveCount(1)->and($this->log->summary()[0]['count'])->toBe(1)
        ->and(glob($this->dir.'/*.tsv'))->toHaveCount(1);
});
