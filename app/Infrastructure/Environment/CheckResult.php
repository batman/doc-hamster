<?php

declare(strict_types=1);

namespace App\Infrastructure\Environment;

/**
 * Outcome of one environment check. Holds translation keys instead of text so
 * the checks stay framework-free; the caller renders them via lang/<locale>/doctor.php.
 */
final readonly class CheckResult
{
    /**
     * @param  string  $key  check identifier, also the label key `doctor.checks.<key>`
     * @param  string  $detail  detail key `doctor.details.<detail>`
     * @param  array<string, string>  $params  placeholders for the detail text
     */
    public function __construct(
        public string $key,
        public CheckStatus $status,
        public string $detail,
        public array $params = [],
    ) {}
}
