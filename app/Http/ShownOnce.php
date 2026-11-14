<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A secret shown once right after a redirect (a new password, a key), without a session flash – any other request
 * (window prefetch, badge poll) would eat the flash (point 201.1). It waits a few minutes in the cache, encrypted,
 * under a random token that only the redirect address carries, and only for the person who caused it.
 */
final class ShownOnce
{
    private const MINUTES = 10;

    /**
     * @param  array<string, string>  $values
     * @return string the token for the redirect address
     */
    public static function put(string $scope, string $userId, array $values): string
    {
        $token = Str::random(32);
        Cache::put(self::key($scope, $userId, $token), encrypt($values), now()->addMinutes(self::MINUTES));

        return $token;
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string>|null
     */
    public static function get(string $scope, string $userId, mixed $token, array $fields): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }
        $sealed = Cache::get(self::key($scope, $userId, $token));
        $values = is_string($sealed) ? decrypt($sealed) : null;
        if (! is_array($values)) {
            return null;
        }

        $out = [];
        foreach ($fields as $field) {
            $out[$field] = is_string($values[$field] ?? null) ? $values[$field] : '';
        }

        return $out;
    }

    private static function key(string $scope, string $userId, string $token): string
    {
        return 'shown-once:'.$scope.':'.$userId.':'.hash('sha256', $token);
    }
}
