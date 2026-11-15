<?php

declare(strict_types=1);

namespace App\Domain\Org;

/** Point 215.3: the model's queries follow the role restriction of the current request (RestrictedScope). */
trait RestrictedByRole
{
    public static function bootRestrictedByRole(): void
    {
        static::addGlobalScope(new RestrictedScope);
    }
}
