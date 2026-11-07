<?php

declare(strict_types=1);

namespace App\Domain\Org;

/**
 * The checkboxes of a role, in plain words (#30). Managing people and roles
 * has no checkbox on purpose: someone could promote themselves. Updates do
 * (PO 2026-11-07): "System aktualisieren", and on top "Vorschau-Branches
 * installieren", which only counts together with it.
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
    case Notes = 'notes';
    case Update = 'update';
    case UpdatePreview = 'update_preview';

    /**
     * Checkboxes of a pre-installed role in a new organisation.
     *
     * @return list<self>
     */
    public static function defaultsFor(Role $role): array
    {
        // Updates are never ticked by default; an Admin gives them on purpose.
        return $role === Role::Accountant
            ? [self::ViewAll, self::Export]
            : [self::Upload, self::ViewAll, self::Edit, self::Delete, self::Banking, self::Export, self::Tags, self::Notes];
    }
}
