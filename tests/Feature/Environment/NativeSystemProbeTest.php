<?php

declare(strict_types=1);

use App\Infrastructure\Environment\NativeSystemProbe;

/*
| NativeSystemProbe reads the real server. `user_agent` stands in for the
| size settings here: it is a plain string setting (PHP_INI_ALL), so any value
| – valid or malformed – can be set at runtime without touching real limits.
*/

function withIni(string $value, Closure $run): mixed
{
    $previous = ini_get('user_agent');
    $warnings = 0;
    set_error_handler(function () use (&$warnings): bool {
        $warnings++;

        return true;
    });

    try {
        ini_set('user_agent', $value);

        return [$run(), $warnings];
    } finally {
        restore_error_handler();
        ini_set('user_agent', $previous === false ? '' : $previous);
    }
}

it('reads ini sizes in bytes', function (string $value, int $bytes): void {
    [$actual, $warnings] = withIni($value, fn (): int => app(NativeSystemProbe::class)->iniBytes('user_agent'));

    expect($actual)->toBe($bytes)->and($warnings)->toBe(0);
})->with([
    'megabytes' => ['32M', 32 * 1024 * 1024],
    'gigabytes' => ['1G', 1024 * 1024 * 1024],
    'kilobytes' => ['512K', 512 * 1024],
    'lowercase suffix' => ['64m', 64 * 1024 * 1024],
    'plain bytes' => ['1024', 1024],
    'explicit plus' => ['+32M', 32 * 1024 * 1024],
    'negative other than -1' => ['-2', -2],
    'zero' => ['0', 0],
    'unlimited' => ['-1', -1],
    'surrounding whitespace' => ['  16M  ', 16 * 1024 * 1024],
]);

it('reports malformed ini values as zero without raising a warning', function (string $value): void {
    [$actual, $warnings] = withIni($value, fn (): int => app(NativeSystemProbe::class)->iniBytes('user_agent'));

    expect($actual)->toBe(0)->and($warnings)->toBe(0);
})->with([
    'word' => ['bogus'],
    'trailing garbage' => ['12x'],
    'suffix only' => ['M'],
    'empty' => [''],
    'only whitespace' => ['   '],
    'double suffix' => ['32MM'],
    'float' => ['1.5G'],
    'overflowing digits' => ['99999999999999999999999'],
    'thousands separator' => ['1,024M'],
]);

it('reports an unknown ini key as zero', function (): void {
    expect(app(NativeSystemProbe::class)->iniBytes('dochamster.no_such_key'))->toBe(0);
});
