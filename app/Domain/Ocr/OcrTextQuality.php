<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

/**
 * A346: does a recognised text look broken – e.g. a Greek letter read with deu+eng only? Simple rule, judged only
 * on texts with at least 20 non-space characters:
 *  1. junk characters: more than 25 % of the characters are neither letters, digits nor usual receipt punctuation
 *     (full stop, comma, colon, semicolon, hyphen, slash, percent, euro, dollar, plus, brackets, quotes, hash,
 *     ampersand, star, at sign), or
 *  2. confetti: of at least 10 words with letters, more than half have only one or two letters, or
 *  3. mixed case: of at least 10 words with four letters or more, more than 20 % switch from small to capital
 *     letters inside the word ("AoyaplaoytoU" – Latin look-alikes of Greek; "GmbH" or "MwSt" alone stay far below).
 */
final class OcrTextQuality
{
    public static function looksBroken(string $text): bool
    {
        $chars = preg_match_all('/\S/u', $text);
        if ($chars === false || $chars < 20) {
            return false;
        }
        $junk = preg_match_all('/[^\s\p{L}\p{N}.,:;\-\/%€$+()\'"#&*@]/u', $text);
        if ($junk !== false && $junk / $chars > 0.25) {
            return true;
        }
        $words = array_values(array_filter(preg_split('/\s+/u', $text) ?: [], static fn (string $w): bool => preg_match('/\p{L}/u', $w) === 1));
        $letters = array_map(static fn (string $w): int => (int) preg_match_all('/\p{L}/u', $w), $words);
        if (count($words) >= 10 && count(array_filter($letters, static fn (int $n): bool => $n <= 2)) / count($words) > 0.5) {
            return true;
        }
        $long = array_values(array_filter($words, static fn (string $w): bool => preg_match_all('/\p{L}/u', $w) >= 4));
        if (count($long) >= 10) {
            $mixed = count(array_filter($long, static fn (string $w): bool => preg_match('/\p{Ll}\p{Lu}/u', $w) === 1));

            return $mixed / count($long) > 0.2;
        }

        return false;
    }
}
