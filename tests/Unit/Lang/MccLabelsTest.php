<?php

declare(strict_types=1);

it('labels every MCC with a 4-digit code and a non-empty German string', function (): void {
    $labels = require __DIR__.'/../../../lang/de/mcc.php';

    expect($labels)->toBeArray()->not->toBeEmpty();

    foreach ($labels as $code => $label) {
        expect((string) $code)->toMatch('/^\d{4}$/')
            ->and($label)->toBeString()
            ->and(trim($label))->not->toBe('');
    }
});
