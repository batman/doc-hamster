<?php

declare(strict_types=1);

namespace App\Domain\Org;

/**
 * The checkboxes of a role, in plain words (#30). Managing people and roles
 * has no checkbox on purpose: someone could promote themselves. Updates do
 * (PO 2026-11-07): "System aktualisieren", and on top "Vorschau-Branches
 * installieren", which only counts together with it. Point 215: "Belegpakete"
 * (see and download the finished receipt packs).
 */
enum RoleRight: string
{
    case Upload = 'upload';
    case ViewAll = 'view_all';
    case Edit = 'edit';
    case Delete = 'delete';
    case Banking = 'banking';
    case Export = 'export';
    case Packs = 'packs';
    case Tags = 'tags';
    case Notes = 'notes';
    case Update = 'update';
    case UpdatePreview = 'update_preview';

    /** Point 216: the groups of the Rollen page (Dokumente, Bank, Export, System). */
    public function group(): string
    {
        return match ($this) {
            self::Upload, self::ViewAll, self::Edit, self::Delete, self::Tags, self::Notes => 'documents',
            self::Banking => 'bank',
            self::Export, self::Packs => 'export',
            self::Update, self::UpdatePreview => 'system',
        };
    }

    /**
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        $groups = ['documents' => [], 'bank' => [], 'export' => [], 'system' => []];
        foreach (self::cases() as $right) {
            $groups[$right->group()][] = $right;
        }

        return $groups;
    }

    /**
     * Checkboxes of the pre-installed role in a new organisation ("Mitarbeiter"); updates are never ticked by default.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [self::Upload, self::ViewAll, self::Edit, self::Delete, self::Banking, self::Export, self::Packs, self::Tags, self::Notes];
    }

    /**
     * The read-only "Steuerberater" role a new organisation starts with (point 215).
     *
     * @return list<self>
     */
    public static function advisor(): array
    {
        return [self::ViewAll, self::Packs];
    }
}
