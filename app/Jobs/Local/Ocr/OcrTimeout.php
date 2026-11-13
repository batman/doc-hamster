<?php

declare(strict_types=1);

namespace App\Jobs\Local\Ocr;

use RuntimeException;

/** A read of a document ran over its time limit (point 130); the message is the limit in seconds. */
final class OcrTimeout extends RuntimeException
{
    public function __construct(public readonly int $seconds)
    {
        parent::__construct((string) $seconds);
    }
}
