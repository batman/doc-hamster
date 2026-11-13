<?php

declare(strict_types=1);

namespace App\Jobs\Local\Ocr;

/**
 * The time limit of one read of a document (point 130/A275: 10 minutes); every program run gets what is left.
 */
final class Deadline
{
    private readonly int $end;

    public function __construct(public readonly int $seconds)
    {
        $this->end = time() + $seconds;
    }

    /** Seconds left, at least 1; throws OcrTimeout when the time is up. */
    public function left(): int
    {
        $left = $this->end - time();
        if ($left <= 0) {
            throw new OcrTimeout($this->seconds);
        }

        return $left;
    }

    public function passed(int $margin = 0): bool
    {
        return $this->end - time() <= $margin;
    }
}
