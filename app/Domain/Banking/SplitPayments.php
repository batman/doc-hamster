<?php

declare(strict_types=1);

namespace App\Domain\Banking;

/**
 * A47: one document paid in parts – a purchase contract in four transfers, a deposit and a rent. Pure: finds other
 * bookings that, together with the given one, add up to the document's amount exactly.
 */
final class SplitPayments
{
    /** Points for a booking that is one part of the document's amount. */
    public const POINTS = 30;

    /**
     * @param  int  $total  the document's amount (minor units, positive)
     * @param  int  $part  this booking's amount (positive)
     * @param  array<string, int>  $others  other bookings near in time: id => amount (positive)
     * @param  int  $more  how many other parts at most
     * @return list<string>|null the other parts, null when none add up
     */
    public static function find(int $total, int $part, array $others, int $more = 3): ?array
    {
        $rest = $total - $part;
        if ($part <= 0 || $rest <= 0) {
            return null;
        }
        $pool = array_filter($others, static fn (int $a): bool => $a > 0 && $a <= $rest);
        arsort($pool);
        $ids = array_keys($pool);
        $amounts = array_values($pool);

        return self::search($amounts, $ids, 0, $rest, $more, []);
    }

    /**
     * @param  list<int>  $amounts  descending
     * @param  list<string>  $ids
     * @param  list<string>  $taken
     * @return list<string>|null
     */
    private static function search(array $amounts, array $ids, int $from, int $rest, int $left, array $taken): ?array
    {
        if ($rest === 0) {
            return $taken;
        }
        if ($left === 0) {
            return null;
        }
        for ($i = $from, $n = count($amounts); $i < $n; $i++) {
            if ($amounts[$i] > $rest) {
                continue;
            }
            // Descending: what is left of the list cannot reach the rest any more.
            if ($rest > $amounts[$i] * $left) {
                return null;
            }
            $found = self::search($amounts, $ids, $i + 1, $rest - $amounts[$i], $left - 1, [...$taken, $ids[$i]]);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
