<?php

declare(strict_types=1);

use App\Domain\Org\Membership;
use App\Domain\ReceiptPack\PackPeriod;
use App\Domain\ReceiptPack\ReceiptPack;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('counts the version per period and organisation: a change means a new pack (point 211.4)', function (): void {
    $owner = Membership::factory()->create();
    $other = Membership::factory()->create();
    $quarter = PackPeriod::of('2026-07-01', '2026-09-30');

    $first = ReceiptPack::start($owner->org_id, $owner->user_id, $quarter, ['a'], ReceiptPack::QUEUED);
    $second = ReceiptPack::start($owner->org_id, $owner->user_id, $quarter, ['a', 'b'], ReceiptPack::QUEUED);
    $month = ReceiptPack::start($owner->org_id, $owner->user_id, PackPeriod::of('2026-07-01', '2026-07-31'), ['a'], ReceiptPack::QUEUED);
    $foreign = ReceiptPack::start($other->org_id, $other->user_id, $quarter, ['x'], ReceiptPack::QUEUED);

    expect([$first->version, $second->version, $month->version, $foreign->version])->toBe([1, 2, 1, 1])
        ->and($second->fileName())->toBe('Belegpaket_2026-Q3_v2.pdf');
});
