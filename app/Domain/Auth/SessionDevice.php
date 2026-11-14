<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Point 208: what the list "Angemeldete Geräte" shows of a session row – a readable device name from the user agent
 * (own small parser, browser + system) and the IP shortened so the full address never shows (A374).
 */
final class SessionDevice
{
    /** Order matters: Edge and Opera carry "Chrome", Chrome carries "Safari". */
    private const BROWSERS = [
        'Edg/' => 'Edge', 'EdgA/' => 'Edge', 'EdgiOS/' => 'Edge',
        'OPR/' => 'Opera', 'SamsungBrowser/' => 'Samsung Internet',
        'Firefox/' => 'Firefox', 'FxiOS/' => 'Firefox',
        'CriOS/' => 'Chrome', 'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    /** Order matters: iPhone and Android say "like Mac OS X" or "Linux". */
    private const SYSTEMS = [
        'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android',
        'Windows' => 'Windows', 'CrOS' => 'ChromeOS', 'Macintosh' => 'Mac', 'Mac OS X' => 'Mac', 'Linux' => 'Linux',
    ];

    /**
     * @return array{browser: string|null, system: string|null}
     */
    public static function parse(?string $userAgent): array
    {
        $agent = (string) $userAgent;

        return ['browser' => self::first(self::BROWSERS, $agent), 'system' => self::first(self::SYSTEMS, $agent)];
    }

    /** True for a phone or tablet, for the row icon. */
    public static function isMobile(?string $userAgent): bool
    {
        return in_array(self::parse($userAgent)['system'], ['iPhone', 'iPad', 'Android'], true);
    }

    /** IPv4: last octet → x. IPv6: the first three groups, then "…". Anything else: null (never shown raw). */
    public static function shortIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return preg_replace('/\.\d+$/', '.x', $ip);
        }
        $packed = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? inet_pton($ip) : false;
        if ($packed === false) {
            return null;
        }
        $groups = str_split(bin2hex($packed), 4);

        return implode(':', array_map(static fn (string $g): string => ltrim($g, '0') === '' ? '0' : ltrim($g, '0'), array_slice($groups, 0, 3))).':…';
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function first(array $map, string $agent): ?string
    {
        foreach ($map as $needle => $name) {
            if (str_contains($agent, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
