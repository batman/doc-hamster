<?php

declare(strict_types=1);

use App\Domain\Org\Role;

it('has the three roles of PLAN §2 with stable stored values', function (): void {
    expect(array_map(fn (Role $role): string => $role->value, Role::cases()))
        ->toBe(['owner', 'member', 'accountant']);
});

it('never invites tax advisors and lets only them sign in with a password (D-012)', function (Role $role, bool $invitable, bool $password): void {
    expect($role->canBeInvited())->toBe($invitable)
        ->and($role->signsInWithPassword())->toBe($password);
})->with([
    'owner' => [Role::Owner, true, false],
    'member' => [Role::Member, true, false],
    'accountant' => [Role::Accountant, false, true],
]);
