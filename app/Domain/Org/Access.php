<?php

declare(strict_types=1);

namespace App\Domain\Org;

/**
 * One cell of the permission matrix (PLAN §2.1). Only `Allow`, `Deny` and
 * `OrgSetting` can be decided for the whole organisation; the others need
 * the concrete resource (own document, share scope) and are decided by the
 * resource policies that come with those features.
 */
enum Access
{
    case Allow;
    case Deny;
    /** Only the user's own resources. */
    case OwnOnly;
    /** Allowed if the organisation setting is switched on. */
    case OrgSetting;
    /** Own resources only, and only if the organisation setting is on. */
    case OwnOnlyIfOrgSetting;
    /** Only what a tax advisor share covers (Phase 7). */
    case ShareOnly;
}
