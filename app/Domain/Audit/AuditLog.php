<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Infrastructure\Crypto\IpHasher;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Writes the audit trail. Every module records through here (PLAN §11.3:
 * logins, pairing, uploads, tax advisor views, changes, exports, downloads,
 * permission changes); the owner's audit view is part of Sharing (P7-3).
 *
 * Actions are dotted lower-case names, e.g. `auth.login`, `org.member_revoked`.
 * `meta` must never contain secrets, tokens, passwords or coordinates.
 */
final readonly class AuditLog
{
    private const ACTION_PATTERN = '/^[a-z]+(\.[a-z_]+)+$/';

    public function __construct(private IpHasher $ipHasher) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(
        string $action,
        ?string $orgId = null,
        ?string $userId = null,
        ?string $deviceId = null,
        ?Model $subject = null,
        array $meta = [],
        ?string $ip = null,
    ): AuditLogEntry {
        if (preg_match(self::ACTION_PATTERN, $action) !== 1 || strlen($action) > 64) {
            throw new InvalidArgumentException("Invalid audit action name: {$action}");
        }

        $subjectKey = $subject?->getKey();

        return AuditLogEntry::create([
            'org_id' => $orgId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'action' => $action,
            'subject_type' => $subject?->getTable(),
            'subject_id' => is_string($subjectKey) ? $subjectKey : null,
            'meta_json' => $meta === [] ? null : $meta,
            'ip_hash' => $ip === null ? null : $this->ipHasher->hash($ip),
        ]);
    }
}
