<?php

declare(strict_types=1);

namespace App\Domain\Ocr\Invoice;

use App\Domain\Ocr\InvoiceProposal;
use App\Domain\Ocr\OcrWords;

/**
 * The lines InvoiceReader's small readers work on: from word positions (with the box of every word and the line's
 * word height) or from plain text, plus the shared helpers for boxes and proposals.
 *
 * @phpstan-type Span array{s: int, e: int, x: float, y: float, w: float, h: float}
 * @phpstan-type Line array{page: int, i: int, y: float, h: float, text: string, spans: list<Span>}
 * @phpstan-type Box array{x: float, y: float, w: float, h: float}
 */
final class InvoiceLines
{
    /**
     * @param  array{pages: list<array{n?: int, words: list<array<string, mixed>>}>}  $words
     * @return list<Line>
     */
    public static function fromWords(array $words): array
    {
        $out = [];
        $i = 0;
        foreach ($words['pages'] as $index => $page) {
            foreach (OcrWords::lines($page) as $line) {
                $text = '';
                $spans = [];
                $top = 1.0;
                $height = 0.0;
                foreach ($line as $word) {
                    $t = (string) ($word['t'] ?? '');
                    if ($t === '') {
                        continue;
                    }
                    $text .= $text === '' ? '' : ' ';
                    $spans[] = ['s' => strlen($text), 'e' => strlen($text) + strlen($t), 'x' => (float) $word['x'], 'y' => (float) $word['y'], 'w' => (float) $word['w'], 'h' => (float) $word['h']];
                    $text .= $t;
                    $top = min($top, (float) $word['y']);
                    $height = max($height, (float) $word['h']);
                }
                if ($text !== '') {
                    $out[] = ['page' => (int) ($page['n'] ?? $index + 1), 'i' => $i++, 'y' => $top, 'h' => $height, 'text' => $text, 'spans' => $spans];
                }
            }
        }

        return $out;
    }

    /**
     * @return list<Line>
     */
    public static function fromText(string $text): array
    {
        $out = [];
        $i = 0;
        foreach (explode("\f", $text) as $index => $page) {
            $rows = preg_split('/\R/u', $page) ?: [];
            $count = max(1, count($rows));
            foreach ($rows as $row => $line) {
                $line = trim((string) preg_replace('/[ \t]+/u', ' ', $line));
                if ($line !== '') {
                    $out[] = ['page' => $index + 1, 'i' => $i++, 'y' => (float) ($row / $count), 'h' => 0.0, 'text' => $line, 'spans' => []];
                }
            }
        }

        return $out;
    }

    /**
     * The box around the words a match covers (byte offsets in the line text).
     *
     * @param  array{spans: list<Span>}  $line
     * @return Box|null
     */
    public static function box(array $line, int $start, int $end): ?array
    {
        $x1 = $y1 = 1.0;
        $x2 = $y2 = 0.0;
        $hit = false;
        foreach ($line['spans'] as $span) {
            if ($span['s'] < $end && $span['e'] > $start) {
                $hit = true;
                $x1 = min($x1, $span['x']);
                $y1 = min($y1, $span['y']);
                $x2 = max($x2, $span['x'] + $span['w']);
                $y2 = max($y2, $span['y'] + $span['h']);
            }
        }

        return $hit ? ['x' => round($x1, 4), 'y' => round($y1, 4), 'w' => round($x2 - $x1, 4), 'h' => round($y2 - $y1, 4)] : null;
    }

    /**
     * @param  Line  $line
     * @param  Box|null  $box
     */
    public static function proposal(string $value, float $confidence, array $line, ?array $box = null, ?int $minor = null, ?string $currency = null, ?string $original = null, ?string $keyword = null): InvoiceProposal
    {
        return new InvoiceProposal($value, round(min(1.0, max(0.0, $confidence)), 2), $line['page'], $box, $minor, $currency, $original, $keyword);
    }

    public static function lower(string $text): string
    {
        return mb_strtolower($text);
    }
}
