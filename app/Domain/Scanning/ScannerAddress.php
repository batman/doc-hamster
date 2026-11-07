<?php

declare(strict_types=1);

namespace App\Domain\Scanning;

/**
 * The address of a network scanner, checked before the server ever calls it:
 * only http(s) to a host on the private network. Loopback, link-local (cloud
 * metadata), public addresses and credentials in the URL are refused, so the
 * scanner setting cannot be used to make the server call other services.
 */
final class ScannerAddress
{
    /**
     * @return string normalised `scheme://host[:port]`
     *
     * @throws \InvalidArgumentException with a translation key of `scan.address_*`
     */
    public static function normalise(string $input): string
    {
        $input = trim($input);
        $parts = parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $input) === 1 ? $input : 'http://'.$input);

        if ($parts === false || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('scan.address_invalid');
        }
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['path'] ?? '') !== '' && $parts['path'] !== '/') {
            throw new \InvalidArgumentException('scan.address_invalid');
        }

        $host = trim(strtolower($parts['host']), '[]');
        $port = $parts['port'] ?? null;
        // parse_url() already refuses ports above 65535.
        if ($port !== null && $port < 1) {
            throw new \InvalidArgumentException('scan.address_invalid');
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : self::resolve($host);
        if ($ip === null || ! self::isPrivate($ip)) {
            throw new \InvalidArgumentException('scan.address_not_private');
        }

        $hostPart = str_contains($host, ':') ? '['.$host.']' : $host;

        return strtolower($parts['scheme']).'://'.$hostPart.($port !== null ? ':'.$port : '');
    }

    /** Only RFC 1918 (10/8, 172.16/12, 192.168/16) and IPv6 unique-local (fc00::/7). */
    public static function isPrivate(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);

            return $long !== false && (
                ($long & 0xFF000000) === 0x0A000000
                || ($long & 0xFFF00000) === 0xAC100000
                || ($long & 0xFFFF0000) === 0xC0A80000
            );
        }
        $packed = @inet_pton($ip);

        return $packed !== false && strlen($packed) === 16 && (ord($packed[0]) & 0xFE) === 0xFC;
    }

    private static function resolve(string $host): ?string
    {
        if (preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $host) !== 1) {
            return null;
        }
        $ip = gethostbyname($host);

        return $ip !== $host ? $ip : null;
    }
}
