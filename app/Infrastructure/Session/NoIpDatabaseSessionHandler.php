<?php

declare(strict_types=1);

namespace App\Infrastructure\Session;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Laravel's database session handler without the client IP: nothing needs it
 * in `sessions`, and not storing it is simpler than hashing it (PLAN §11.3).
 * Registered as the `database` session driver in AppServiceProvider.
 */
final class NoIpDatabaseSessionHandler extends DatabaseSessionHandler
{
    protected function ipAddress(): ?string
    {
        return null;
    }
}
