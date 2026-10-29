<?php

declare(strict_types=1);

namespace App\Domain\Org;

use LogicException;

/**
 * An org-scoped model was used without an active organisation. Fails closed:
 * better an error than a query over every organisation's data (PLAN §11.2).
 */
final class MissingOrgContext extends LogicException
{
    public function __construct()
    {
        parent::__construct('No active organisation – set CurrentOrg (middleware or job) or query explicitly withoutGlobalScope(OrgScope::class).');
    }
}
