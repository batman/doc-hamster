<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

use App\Domain\Documents\Document;
use App\Domain\Org\OrgScope;
use App\Domain\Properties\PropertyValues;
use Illuminate\Validation\ValidationException;

/**
 * OCR corrections by position (point 122.7, A243). A fix replaces the text of the word whose centre lies in its box
 * (documents.ocr_fixes, applied when reading by OcrWords); the searchable documents.ocr_text is patched along, also
 * after a new recognition. With a sender value, original → corrected is learned per sender and offered elsewhere.
 */
final class OcrFixes
{
    /** Longest word that is learned per sender (column size). */
    private const int LEARN_MAX = 100;

    public function __construct(private readonly PropertyValues $values) {}

    /**
     * Stores the correction of the word on page $page whose centre lies in $box; the corrected text equal to the
     * recognised one removes the fix. Throws a ValidationException (`box`) when no word is there.
     *
     * @param  array{x: float, y: float, w: float, h: float}  $box
     */
    public function fix(Document $d, int $page, array $box, string $text): void
    {
        $text = trim($text);
        [$raw, $current, $pi, $wi] = $this->target($d, $page, $box);
        $word = $current['pages'][$pi]['words'][$wi];
        $original = (string) $raw['pages'][$pi]['words'][$wi]['t'];
        $before = (string) $word['t'];
        [$fixes, $removed] = self::without(OcrWords::fixes($d), $page, $word);
        if ($text !== $original && $text !== '') {
            $fixes[] = ['p' => $page, 'x' => (float) $word['x'], 'y' => (float) $word['y'], 'w' => (float) $word['w'], 'h' => (float) $word['h'], 't' => $text, 'o' => $original];
        }
        $after = $text !== '' ? $text : $original;
        $this->save($d, $fixes, self::patchText((string) $d->getAttribute('ocr_text'), $before, $after, self::nth($current, $pi, $wi)));

        $sender = $this->senderKey($d);
        if ($sender !== null) {
            foreach ($removed as $old) {
                $this->learn($d->org_id, $sender, $old['o'], $old['t'], -1);
            }
            if ($after !== $original) {
                $this->learn($d->org_id, $sender, $original, $after, 1);
            }
        }
    }

    /**
     * Removes the correction of the word at that place (undo): the recognised text comes back.
     *
     * @param  array{x: float, y: float, w: float, h: float}  $box
     */
    public function unfix(Document $d, int $page, array $box): void
    {
        [$raw, , $pi, $wi] = $this->target($d, $page, $box);
        $this->fix($d, $page, $box, (string) $raw['pages'][$pi]['words'][$wi]['t']);
    }

    /**
     * The new text of a recognition with the document's corrections put in again (they survive a re-scan): each
     * fix finds its word in the new words by position and replaces that word's token in the text.
     *
     * @param  array{v: int, source: string, pages: list<array{n: int, words: list<array<string, mixed>>}>}|null  $words  the new, uncorrected words
     */
    public static function reapply(Document $d, string $text, ?array $words): string
    {
        $fixes = OcrWords::fixes($d);
        if ($words === null || $fixes === []) {
            return $text;
        }
        foreach ($fixes as $fix) {
            $at = self::locate($words, $fix['p'], $fix);
            if ($at === null) {
                continue;
            }
            [$pi, $wi] = $at;
            $now = (string) $words['pages'][$pi]['words'][$wi]['t'];
            if ($now !== $fix['t']) {
                $text = self::patchText($text, $now, $fix['t'], self::nth($words, $pi, $wi));
                $words = OcrWords::applyFixes($words, [$fix]);
            }
        }

        return $text;
    }

    /**
     * A243: the corrections learned for this document's sender, original => corrected (the most frequent one).
     *
     * @return array<string, string>
     */
    public function learnedFor(Document $d): array
    {
        $sender = $this->senderKey($d);
        if ($sender === null) {
            return [];
        }
        $out = [];
        OcrLearned::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $d->org_id)->where('sender_key', $sender)
            ->where('count', '>', 0)->orderBy('count')->get(['original', 'corrected'])
            ->each(static function (OcrLearned $row) use (&$out): void {
                $out[$row->original] = $row->corrected; // ascending: the most frequent wins
            });

        return $out;
    }

    /** The sender as a key: the value of the property "sender", lower case, single spaces; null without one. */
    public function senderKey(Document $d): ?string
    {
        $value = $this->values->get($d, 'sender');
        if ($value === null) {
            return null;
        }
        $key = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $this->values->display($value))));

        return $key !== '' ? mb_substr($key, 0, 100) : null;
    }

    /**
     * Replaces the $nth whole-token occurrence (0-based, separated by white space) of $from in $text; the first one
     * when there are fewer, nothing when there is none.
     */
    public static function patchText(string $text, string $from, string $to, int $nth): string
    {
        if ($from === '' || $from === $to) {
            return $text;
        }
        if (preg_match_all('/(?<!\S)'.preg_quote($from, '/').'(?!\S)/u', $text, $m, PREG_OFFSET_CAPTURE) < 1) {
            return $text;
        }
        $offset = ($m[0][$nth] ?? $m[0][0])[1];

        return substr($text, 0, $offset).$to.substr($text, $offset + strlen($from));
    }

    /**
     * @param  array{x: float, y: float, w: float, h: float}  $box
     * @return array{0: array{pages: list<array{n: int, words: list<array<string, mixed>>}>}, 1: array{pages: list<array{n: int, words: list<array<string, mixed>>}>}, 2: int, 3: int}
     */
    private function target(Document $d, int $page, array $box): array
    {
        $raw = OcrWords::raw($d);
        $current = OcrWords::forDocument($d);
        $at = $raw !== null && $current !== null ? self::locate($current, $page, $box) : null;
        if ($raw === null || $current === null || $at === null) {
            throw ValidationException::withMessages(['box' => __('documents.words.no_word')]);
        }

        return [$raw, $current, $at[0], $at[1]];
    }

    /**
     * The first word of page $p whose centre lies in the box, as [page index, word index].
     *
     * @param  array{pages: list<array{n: int, words: list<array<string, mixed>>}>}  $words
     * @param  array{x: float, y: float, w: float, h: float}  $box
     * @return array{0: int, 1: int}|null
     */
    private static function locate(array $words, int $p, array $box): ?array
    {
        foreach ($words['pages'] as $pi => $page) {
            if ($page['n'] !== $p) {
                continue;
            }
            foreach ($page['words'] as $wi => $word) {
                $cx = (float) $word['x'] + (float) $word['w'] / 2;
                $cy = (float) $word['y'] + (float) $word['h'] / 2;
                if ($cx >= $box['x'] && $cx <= $box['x'] + $box['w'] && $cy >= $box['y'] && $cy <= $box['y'] + $box['h']) {
                    return [$pi, $wi];
                }
            }
        }

        return null;
    }

    /**
     * How many words before this one (reading order over all pages) have the same text.
     *
     * @param  array{pages: list<array{n: int, words: list<array<string, mixed>>}>}  $words
     */
    private static function nth(array $words, int $pi, int $wi): int
    {
        $text = $words['pages'][$pi]['words'][$wi]['t'];
        $n = 0;
        foreach ($words['pages'] as $i => $page) {
            foreach ($page['words'] as $j => $word) {
                if ($i === $pi && $j === $wi) {
                    return $n;
                }
                $n += $word['t'] === $text ? 1 : 0;
            }
        }

        return $n;
    }

    /**
     * The fixes without those of this word, and the removed ones.
     *
     * @param  list<array{p: int, x: float, y: float, w: float, h: float, t: string, o: string}>  $fixes
     * @param  array<string, mixed>  $word
     * @return array{0: list<array{p: int, x: float, y: float, w: float, h: float, t: string, o: string}>, 1: list<array{p: int, x: float, y: float, w: float, h: float, t: string, o: string}>}
     */
    private static function without(array $fixes, int $page, array $word): array
    {
        $cx = (float) $word['x'] + (float) $word['w'] / 2;
        $cy = (float) $word['y'] + (float) $word['h'] / 2;
        $keep = [];
        $removed = [];
        foreach ($fixes as $fix) {
            $hit = $fix['p'] === $page && $cx >= $fix['x'] && $cx <= $fix['x'] + $fix['w'] && $cy >= $fix['y'] && $cy <= $fix['y'] + $fix['h'];
            if ($hit) {
                $removed[] = $fix;
            } else {
                $keep[] = $fix;
            }
        }

        return [$keep, $removed];
    }

    /** @param  list<array<string, mixed>>  $fixes */
    private function save(Document $d, array $fixes, string $text): void
    {
        $d->forceFill([
            'ocr_fixes' => $fixes !== [] ? json_encode($fixes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'ocr_text' => $text !== '' ? $text : $d->getAttribute('ocr_text'),
        ])->save();
    }

    private function learn(string $orgId, string $sender, string $original, string $corrected, int $step): void
    {
        if ($original === '' || $original === $corrected || mb_strlen($original) > self::LEARN_MAX || mb_strlen($corrected) > self::LEARN_MAX) {
            return;
        }
        $row = OcrLearned::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId)
            ->where('sender_key', $sender)->where('original', $original)->where('corrected', $corrected)->first();
        if ($row === null) {
            if ($step > 0) {
                OcrLearned::query()->create(['org_id' => $orgId, 'sender_key' => $sender, 'original' => $original, 'corrected' => $corrected, 'count' => $step]);
            }

            return;
        }
        if ($row->count + $step <= 0) {
            $row->delete();
        } else {
            $row->forceFill(['count' => $row->count + $step])->save();
        }
    }
}
