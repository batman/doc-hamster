<?php

declare(strict_types=1);

namespace App\Domain\Backup\Restore;

use RuntimeException;

/**
 * The restore stopped before anything was written (or rolled back). The message is for people (lang/backup.php);
 * `reason` is a short stable key for tests and callers like the setup wizard.
 */
final class RestoreFailed extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $reason, array $params = [])
    {
        parent::__construct((string) __('backup.restore.errors.'.$reason, $params));
    }
}
