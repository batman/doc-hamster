<?php

declare(strict_types=1);

use App\Domain\Org\Role;

it('has the two kinds of membership with stable stored values (point 215: no tax-advisor kind)', function (): void {
    expect(array_map(fn (Role $role): string => $role->value, Role::cases()))
        ->toBe(['owner', 'member']);
});
