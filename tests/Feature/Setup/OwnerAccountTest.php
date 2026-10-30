<?php

declare(strict_types=1);

use App\Domain\Org\Membership;
use App\Domain\Org\Organization;
use App\Domain\Org\Role;
use App\Setup\OwnerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('db');

it('lets a new owner take over the organisation kept by a reset', function (): void {
    $kept = Organization::factory()->create(['name' => 'Alt']);

    $owner = OwnerAccount::create('Neu', 'inhaber', 'ein-langes-test-passwort');

    expect(Organization::count())->toBe(1)
        ->and($kept->fresh()?->name)->toBe('Neu')
        ->and($owner->memberships()->firstOrFail()->org_id)->toBe($kept->id);
});

it('creates its own organisation when another owner exists', function (): void {
    $existing = Membership::factory()->create(['role' => Role::Owner]);

    $owner = OwnerAccount::create('Zweite', 'inhaber2', 'ein-langes-test-passwort');

    expect(Organization::count())->toBe(2)
        ->and($owner->memberships()->firstOrFail()->org_id)->not->toBe($existing->org_id);
});
