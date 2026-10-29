<?php

declare(strict_types=1);

use App\Domain\Org\Invitation;
use Carbon\CarbonImmutable;

it('is usable only before it expires and only once', function (): void {
    $now = CarbonImmutable::parse('2026-10-29 12:00:00');
    $invitation = new Invitation(['expires_at' => $now->addMinutes(10)]);

    expect($invitation->isUsable($now))->toBeTrue()
        ->and($invitation->isUsable($now->addMinutes(10)))->toBeFalse();

    $invitation->used_at = $now;

    expect($invitation->isUsable($now))->toBeFalse();
});
