<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('forbids outbound stream wrappers and network aliases outside net jobs (D-020)', function (): void {
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir().'/egress-phpstan-'.uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/phpstan.neon', <<<NEON
        parameters:
            level: 8
            tmpDir: {$dir}/cache
        rules:
            - Tests\Support\PhpStan\DisallowHttpStreamWrappersRule
            - Tests\Support\PhpStan\DisallowNetworkFacadeAliasesRule
        NEON);

    try {
        $process = new Process([
            PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--no-progress',
            '--error-format=json', '--memory-limit=1G',
            '--autoload-file=vendor/autoload.php', '-c', $dir.'/phpstan.neon',
            'tests/fixtures/egress/stubs.php',
            'tests/fixtures/egress/bad.php',
            'tests/fixtures/egress/good.php',
            'tests/fixtures/egress/net.php',
        ], cwd: $root, timeout: 300);
        $process->run();

        $decoded = json_decode($process->getOutput(), true);
        expect($decoded)->toBeArray('PHPStan produced no JSON output: '.$process->getErrorOutput());

        /** @var array<string, list<string>> $byFile */
        $byFile = [];
        foreach ($decoded['files'] ?? [] as $path => $info) {
            $byFile[basename((string) $path)] = array_column($info['messages'] ?? [], 'message');
        }

        // Eight remote stream-wrapper calls plus three global alias calls.
        $bad = $byFile['bad.php'] ?? [];
        expect($bad)->toHaveCount(11);
        foreach ($bad as $message) {
            expect($message)->toContain('D-020');
        }

        // Local lookalikes, net jobs and the stubs themselves stay silent.
        expect($byFile['good.php'] ?? [])->toBe([])
            ->and($byFile['net.php'] ?? [])->toBe([])
            ->and($byFile['stubs.php'] ?? [])->toBe([]);
    } finally {
        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path.'/'.$entry);
                    }
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        };
        $remove($dir);
    }
});
