<?php

declare(strict_types=1);

namespace App\Infrastructure\Crypto;

/**
 * IP addresses are only stored as keyed hashes (PLAN §11.3, DSGVO): equal IPs
 * still compare equal (e.g. "new location" hints), but the stored value can't
 * be turned back into an address without the app key – a plain SHA-256 could
 * be reversed by hashing all 2^32 IPv4 addresses.
 */
final readonly class IpHasher
{
    public function __construct(#[\SensitiveParameter] private string $key) {}

    public function hash(string $ip): string
    {
        return hash_hmac('sha256', 'ip:'.$ip, $this->key);
    }
}
