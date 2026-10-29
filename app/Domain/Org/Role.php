<?php

declare(strict_types=1);

namespace App\Domain\Org;

/**
 * Role of a user inside one organisation (PLAN §2, D-012). Owners and members
 * sign in with passkeys; the tax advisor (`accountant`) with username and
 * password and only sees what a share allows.
 */
enum Role: string
{
    case Owner = 'owner';
    case Member = 'member';
    case Accountant = 'accountant';

    /**
     * Tax advisor accounts are created by the owner directly, never through an
     * invitation link (D-012).
     */
    public function canBeInvited(): bool
    {
        return $this !== self::Accountant;
    }

    public function signsInWithPassword(): bool
    {
        return $this === self::Accountant;
    }

    /**
     * The permission matrix of PLAN §2.1, one column per role.
     */
    public function access(Permission $permission): Access
    {
        return match ($this) {
            self::Owner => Access::Allow,
            self::Member => match ($permission) {
                Permission::UploadDocuments, Permission::ManageOwnDocuments => Access::Allow,
                Permission::ViewAllDocuments => Access::OrgSetting,
                Permission::EditDocuments => Access::OwnOnly,
                Permission::TrashDocuments => Access::OwnOnlyIfOrgSetting,
                default => Access::Deny,
            },
            self::Accountant => match ($permission) {
                Permission::ViewAllDocuments, Permission::CreateReports, Permission::Export => Access::ShareOnly,
                default => Access::Deny,
            },
        };
    }
}
