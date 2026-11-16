<?php

declare(strict_types=1);

/*
 * Every Blade view compiles to valid PHP (F48): `php artisan view:cache` alone does not parse the result, so a broken
 * view (e.g. an inline @php … @endphp next to another @php) only showed up as a 500 in the browser or a failing test.
 * Usage: php scripts/check-views.php
 */

$root = dirname(__DIR__);
$php = PHP_BINARY;
$run = static function (array $command) use ($root): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (! is_resource($process)) {
        return [1, 'could not start '.implode(' ', $command)];
    }
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

    return [proc_close($process), (string) $out];
};

[$code, $out] = $run([$php, 'artisan', 'view:cache']);
if ($code !== 0) {
    fwrite(STDERR, $out);
    exit(1);
}
$bad = [];
foreach (glob($root.'/storage/framework/views/*.php') ?: [] as $file) {
    [$code, $out] = $run([$php, '-l', $file]);
    if ($code !== 0) {
        preg_match('/PATH (.*?) ENDPATH/', (string) file_get_contents($file), $m);
        $bad[] = ($m[1] ?? basename($file)).': '.trim((string) preg_replace('/\s+/', ' ', $out));
    }
}
$run([$php, 'artisan', 'view:clear']);

if ($bad !== []) {
    fwrite(STDERR, implode("\n", $bad)."\n".count($bad)." view(s) do not compile to valid PHP.\n");
    exit(1);
}
fwrite(STDOUT, "All views compile.\n");
