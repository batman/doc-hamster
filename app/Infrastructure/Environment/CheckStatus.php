<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

enum CheckStatus: string
{
    /** Requirement met. */
    case Ok = 'ok';

    /** Works, but something is missing or should be fixed (e.g. an add-on). */
    case Warning = 'warning';

    /** Minimum requirement not met – DocHamster must not run like this. */
    case Fail = 'fail';
}
