<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\Request;

/**
 * Foldable cards (point 125, A261): the folds a person chose, kept in the plain cookie `dh_folds`
 * ("page.card:1|page.card:0", written by public/js/core/card-fold.js). The server draws a card folded or open from
 * it, so nothing jumps on load – also in windows loaded later. Not secret, so not encrypted (bootstrap/app.php).
 */
final class CardFolds
{
    public const string COOKIE = 'dh_folds';

    /** @var array<string, bool>|null */
    private static ?array $parsed = null;

    private static ?string $raw = null;

    /** Whether the card `$key` is drawn folded: the person's own choice, else the page's default. */
    public static function folded(string $key, bool $default, ?Request $request = null): bool
    {
        return self::all($request ?? request())[$key] ?? $default;
    }

    /** @return array<string, bool> */
    public static function parse(string $raw): array
    {
        $folds = [];
        foreach (explode('|', $raw) as $entry) {
            $at = strrpos($entry, ':');
            if ($at === false || $at === 0) {
                continue;
            }
            $folds[substr($entry, 0, $at)] = substr($entry, $at + 1) === '1';
        }

        return $folds;
    }

    /** @return array<string, bool> */
    private static function all(Request $request): array
    {
        $raw = $request->cookies->get(self::COOKIE);
        $raw = is_string($raw) ? $raw : '';
        if (self::$parsed === null || self::$raw !== $raw) {
            self::$raw = $raw;
            self::$parsed = self::parse($raw);
        }

        return self::$parsed;
    }
}
