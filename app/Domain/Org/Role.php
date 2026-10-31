<?php

declare(strict_types=1);

namespace App\Domain\Org;

/**
 * Kind of membership inside one organisation (PLAN §2, D-012). Owners (shown
 * as Admin) may always do everything; for everyone else the checkboxes of
 * their OrgRole decide. Owners and members sign in with passkeys; the tax
 * advisor (`accountant`) with username and password.
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
}
