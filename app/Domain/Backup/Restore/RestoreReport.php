<?php

declare(strict_types=1);

namespace App\Domain\Backup\Restore;

/**
 * What a restore did: rows per table, people without an account here (skipped), files the backup did not have.
 */
final readonly class RestoreReport
{
    /**
     * @param  array<string, int>  $rows
     * @param  list<string>  $skippedPeople  display names
     * @param  list<string>  $missingFiles  document file ids
     */
    public function __construct(
        public string $orgId,
        public string $orgName,
        public string $ownerId,
        public array $rows,
        public array $skippedPeople,
        public array $missingFiles,
        public int $files,
    ) {}
}
