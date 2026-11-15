<?php

declare(strict_types=1);

namespace App\Domain\Optimize;

/**
 * Point 236: what the optimiser made of one file – "done" (a smaller file was written), "small" (not over the
 * threshold per page; looked at again when the threshold changes) or "skip" (not a photo or scan, or not 30 % smaller).
 */
final readonly class ShrinkResult
{
    public const string DONE = 'done';

    public const string SMALL = 'small';

    public const string SKIP = 'skip';

    private function __construct(
        public string $status,
        public ?string $reason = null,
        public ?string $mime = null,
        public ?string $extension = null,
        public int $pages = 0,
        public int $before = 0,
        public int $after = 0,
    ) {}

    public static function done(string $mime, string $extension, int $pages, int $before, int $after): self
    {
        return new self(self::DONE, null, $mime, $extension, $pages, $before, $after);
    }

    public static function small(int $pages): self
    {
        return new self(self::SMALL, null, null, null, $pages);
    }

    public static function skip(string $reason, int $pages = 0, int $before = 0, int $after = 0): self
    {
        return new self(self::SKIP, $reason, null, null, $pages, $before, $after);
    }
}
