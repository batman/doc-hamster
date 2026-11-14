<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Hands the ZIP password to the background job without storing it (point 206.4, A373): the job's payload holds the
 * password sealed with a one-off random key; that key lives only in the cache, for a short time, and is taken out
 * on first use. The jobs table alone (also encrypted with the app key, ShouldBeEncrypted) never reveals it.
 */
final class JobSecret
{
    private const string PREFIX = 'backup.key.';

    /** Long enough for a busy queue; a job that starts later fails and asks for a new start. */
    private const int TTL_MINUTES = 120;

    public static function seal(string $backupId, #[\SensitiveParameter] string $password): string
    {
        $key = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        Cache::put(self::PREFIX.$backupId, base64_encode($key), now()->addMinutes(self::TTL_MINUTES));
        $sealed = base64_encode($nonce.sodium_crypto_secretbox($password, $nonce, $key));
        sodium_memzero($key);

        return $sealed;
    }

    /** The password once; the key is gone afterwards. */
    public static function open(string $backupId, string $sealed): string
    {
        $key = Cache::pull(self::PREFIX.$backupId);
        $raw = base64_decode($sealed, true);
        if (! is_string($key) || $raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('The backup key has expired.');
        }
        $password = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), (string) base64_decode($key, true));
        if ($password === false) {
            throw new RuntimeException('The backup key does not fit.');
        }

        return $password;
    }
}
