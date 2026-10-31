<?php

declare(strict_types=1);

namespace App\Domain\Org;

/**
 * The checkboxes of a role, in plain words (#30). Managing people, roles and
 * updates has no checkbox on purpose: someone could promote themselves.
 */
enum RoleRight: string
{
    case Upload = 'upload';
    case ViewAll = 'view_all';
    case Edit = 'edit';
    case Delete = 'delete';
    case Banking = 'banking';
    case Export = 'export';
    case Tags = 'tags';

    /**
     * Checkboxes of a pre-installed role in a new organisation.
     *
     * @return list<self>
     */
    public static function defaultsFor(Role $role): array
    {
        return $role === Role::Accountant ? [self::ViewAll, self::Export] : self::cases();
    }
}
