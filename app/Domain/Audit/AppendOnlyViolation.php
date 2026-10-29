<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use LogicException;

final class AppendOnlyViolation extends LogicException
{
    public static function for(string $operation): self
    {
        return new self("The audit log is append-only; {$operation} is not allowed.");
    }
}
