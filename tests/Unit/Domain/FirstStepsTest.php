<?php

declare(strict_types=1);

use App\Domain\Dashboard\FirstSteps;

it('shows the allowed steps in order, ticked from the data (F31)', function (): void {
    expect(FirstSteps::card(['upload' => true, 'phone' => false, 'account' => false], ['phone', 'upload']))
        ->toBe([['key' => 'upload', 'done' => true], ['key' => 'phone', 'done' => false]]);
});

it('has no card when every allowed step is done or nothing is allowed (F31)', function (): void {
    expect(FirstSteps::card(['account' => true, 'import' => true, 'upload' => false], ['account', 'import']))->toBeNull()
        ->and(FirstSteps::card([], []))->toBeNull();
});
