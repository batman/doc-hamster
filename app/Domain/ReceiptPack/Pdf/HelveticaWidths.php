<?php

declare(strict_types=1);

namespace App\Domain\ReceiptPack\Pdf;

/**
 * Character widths (1/1000 em) of Helvetica and Helvetica-Bold from the standard AFM files, for right-aligned amounts
 * and cutting text to a column. ASCII exactly; the WinAnsi letters the app meets most (umlauts, ß, €, –, …); the rest 556.
 */
final class HelveticaWidths
{
    /** ASCII 32..126, regular. */
    private const string REGULAR = '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,'
        .'1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,'
        .'333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584';

    /** ASCII 32..126, bold. */
    private const string BOLD = '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,'
        .'975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,'
        .'333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584';

    /** WinAnsi byte => [regular, bold] beyond ASCII. */
    private const array EXTRA = [
        0x80 => [556, 556], 0x85 => [1000, 1000], 0x95 => [350, 350], 0x96 => [556, 556], 0x97 => [1000, 1000], 0xB7 => [278, 278],
        0xC4 => [667, 722], 0xD6 => [778, 778], 0xDC => [722, 722], 0xDF => [611, 611], 0xE4 => [556, 556], 0xF6 => [556, 611],
        0xFC => [556, 611], 0xE9 => [556, 556], 0xE8 => [556, 556], 0xE0 => [556, 556], 0xA0 => [278, 278],
    ];

    /** @var array<int, list<int>> */
    private static array $tables = [];

    /** Width in points of WinAnsi text at this size. */
    public static function of(string $ansi, float $size, bool $bold = false): float
    {
        $table = self::table($bold);
        $sum = 0;
        $length = strlen($ansi);
        for ($i = 0; $i < $length; $i++) {
            $code = ord($ansi[$i]);
            $sum += $code >= 32 && $code <= 126 ? $table[$code - 32] : (isset(self::EXTRA[$code]) ? self::EXTRA[$code][$bold ? 1 : 0] : 556);
        }

        return $sum * $size / 1000;
    }

    /** @return list<int> */
    private static function table(bool $bold): array
    {
        return self::$tables[(int) $bold] ??= array_map('intval', explode(',', $bold ? self::BOLD : self::REGULAR));
    }
}
