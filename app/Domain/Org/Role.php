<?php

declare(strict_types=1);

namespace App\Domain\Org;

/**
 * Kind of membership inside one organisation (PLAN §2, D-012). Owners (shown as Admin) may always do everything;
 * for everyone else the checkboxes and restrictions of their OrgRole decide. Point 215: there is no special tax-advisor
 * kind any more – a tax advisor is a member with a role (e.g. "Steuerberater", read-only); everyone signs in the same way.
 */
enum Role: string
{
    case Owner = 'owner';
    case Member = 'member';
}
