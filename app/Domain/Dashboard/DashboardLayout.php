<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * Home dashboard (points 176, A349-A353): which widgets a person shows, in which order and size, and the pinned
 * places of "Schnellzugriff". Everything that comes from a request runs through normalize()/pin(), so the stored
 * layout only ever holds known widgets, known sizes and safe addresses.
 *
 * @phpstan-type Widget array{type: string, size: string}
 * @phpstan-type Pin array{url: string, title: string, icon: string}
 * @phpstan-type Layout array{widgets: list<Widget>, pins: list<Pin>}
 */
final class DashboardLayout
{
    /** @var array<string, array{icon: string, size: string}> every widget type: its symbol and default size */
    public const array WIDGETS = [
        'photo' => ['icon' => 'photo', 'size' => 'l'],
        'greeting' => ['icon' => 'home', 'size' => 'l'],
        'quick' => ['icon' => 'pin', 'size' => 'l'],
        'inbox' => ['icon' => 'inbox', 'size' => 'm'],
        'due' => ['icon' => 'file-invoice', 'size' => 'm'],
        'bookings' => ['icon' => 'receipt-off', 'size' => 'm'],
        'activity' => ['icon' => 'clock', 'size' => 'm'],
        'notes' => ['icon' => 'note', 'size' => 'm'],
        'ocr' => ['icon' => 'text-scan-2', 'size' => 's'],
        'drop' => ['icon' => 'upload', 'size' => 's'],
    ];

    public const array SIZES = ['s', 'm', 'l'];

    /** A352: what a new person sees; Foto and Schnellzugriff start empty with a hint. */
    public const array DEFAULT = ['photo', 'greeting', 'quick', 'inbox', 'due', 'bookings', 'activity'];

    public const int TITLE_MAX = 80;

    public const int URL_MAX = 500;

    /** @return Layout */
    public static function default(): array
    {
        return ['widgets' => array_map(static fn (string $type): array => ['type' => $type, 'size' => self::WIDGETS[$type]['size']], self::DEFAULT), 'pins' => []];
    }

    /**
     * A stored or sent layout made safe: unknown widgets and doubles go, a wrong size becomes the default size,
     * pins are checked one by one.
     *
     * @return Layout
     */
    public static function normalize(mixed $layout): array
    {
        if (! is_array($layout)) {
            return self::default();
        }

        return [
            'widgets' => self::widgets($layout['widgets'] ?? []),
            'pins' => self::pins($layout['pins'] ?? []),
        ];
    }

    /** @return list<Widget> */
    public static function widgets(mixed $widgets): array
    {
        $clean = [];
        foreach (is_array($widgets) ? $widgets : [] as $widget) {
            $type = is_array($widget) ? ($widget['type'] ?? null) : $widget;
            if (! is_string($type) || ! isset(self::WIDGETS[$type]) || isset($clean[$type])) {
                continue;
            }
            $size = is_array($widget) ? ($widget['size'] ?? null) : null;
            $clean[$type] = ['type' => $type, 'size' => is_string($size) && in_array($size, self::SIZES, true) ? $size : self::WIDGETS[$type]['size']];
        }

        return array_values($clean);
    }

    /** @return list<Pin> */
    private static function pins(mixed $pins): array
    {
        $clean = [];
        foreach (is_array($pins) ? $pins : [] as $pin) {
            if (is_array($pin)) {
                $pin = self::pin($pin['url'] ?? null, $pin['title'] ?? null, $pin['icon'] ?? null);
                if ($pin !== null) {
                    $clean[] = $pin;
                }
            }
        }

        return $clean;
    }

    /**
     * One pinned place (A351): an address inside the app (a path, or a full address of this app's own origin, which
     * becomes a path) or an external https link. Anything else (javascript:, http:, other schemes, "//host") is refused.
     *
     * @return Pin|null
     */
    public static function pin(mixed $url, mixed $title, mixed $icon, ?string $origin = null): ?array
    {
        $url = self::url($url, $origin);
        if ($url === null) {
            return null;
        }
        $external = str_starts_with($url, 'https://');
        $title = is_string($title) ? trim((string) preg_replace('/\s+/u', ' ', $title)) : '';
        if ($title === '') {
            $title = $external ? (string) parse_url($url, PHP_URL_HOST) : $url;
        }

        return ['url' => $url, 'title' => mb_substr($title, 0, self::TITLE_MAX), 'icon' => self::icon($icon, $external)];
    }

    public static function isExternal(string $url): bool
    {
        return str_starts_with($url, 'https://');
    }

    private static function url(mixed $url, ?string $origin): ?string
    {
        if (! is_string($url)) {
            return null;
        }
        $url = trim($url);
        if ($url === '' || strlen($url) > self::URL_MAX || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }
        // A full address of this app is kept as its path, so the tile follows the app to another host name.
        if ($origin !== null && $origin !== '' && (strcasecmp($url, $origin) === 0 || stripos($url, rtrim($origin, '/').'/') === 0 || stripos($url, rtrim($origin, '/').'?') === 0)) {
            $url = substr($url, strlen(rtrim($origin, '/')));
            $url = $url === '' || $url[0] !== '/' ? '/'.$url : $url;
        }
        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }
        if (! str_starts_with(strtolower($url), 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? 'https://'.substr($url, 8) : null;
    }

    /** A Tabler symbol the app ships; else link / external-link. */
    private static function icon(mixed $icon, bool $external): string
    {
        if (is_string($icon) && preg_match('/^[a-z0-9-]{1,40}$/', $icon) === 1 && is_file(resource_path('icons/tabler/'.$icon.'.svg'))) {
            return $icon;
        }

        return $external ? 'external-link' : 'link';
    }
}
