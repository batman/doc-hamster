<?php

declare(strict_types=1);

namespace App\Domain\Notes;

/**
 * The text of a note with lists written inline (Go of 2026-11-07):
 * "[Milch, Brot, #Butter]" anywhere in the text is a list, "#" in front of
 * an entry marks it done. A note may hold several lists. Brackets without
 * any entry ("[]") stay plain text.
 *
 * @phpstan-type Item array{text: string, done: bool}
 * @phpstan-type Segment array{type: 'text', text: string}|array{type: 'list', items: list<Item>}
 */
final class NoteText
{
    private const LIST = '/\[([^\[\]]*)\]/u';

    /**
     * The text cut into plain text and lists, in reading order.
     *
     * @return list<Segment>
     */
    public static function parse(string $body): array
    {
        preg_match_all(self::LIST, $body, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $segments = [];
        $pos = 0;
        foreach ($matches as $match) {
            $items = self::items($match[1][0]);
            if ($items === []) {
                continue;
            }
            $segments[] = ['type' => 'text', 'text' => substr($body, $pos, $match[0][1] - $pos)];
            $segments[] = ['type' => 'list', 'items' => $items];
            $pos = $match[0][1] + strlen($match[0][0]);
        }
        $segments[] = ['type' => 'text', 'text' => substr($body, $pos)];

        // The line break right before or after a list belongs to the list.
        $segments = array_map(static fn (array $segment): array => $segment['type'] === 'text'
            ? ['type' => 'text', 'text' => (string) preg_replace('/^\R|\R$/', '', $segment['text'])]
            : $segment, $segments);

        return array_values(array_filter($segments, static fn (array $segment): bool => $segment['type'] === 'list' || trim($segment['text']) !== ''));
    }

    /**
     * The text with entry $item of list $list ticked or unticked. Null when
     * that entry no longer reads $text (someone changed the note meanwhile).
     */
    public static function toggle(string $body, int $list, int $item, string $text): ?string
    {
        preg_match_all(self::LIST, $body, $matches, PREG_OFFSET_CAPTURE);
        $lists = array_values(array_filter($matches[1], static fn (array $inner): bool => self::items($inner[0]) !== []));
        if (! isset($lists[$list])) {
            return null;
        }
        [$inner, $offset] = $lists[$list];

        $parts = explode(',', $inner);
        $n = -1;
        foreach ($parts as $k => $part) {
            if (self::item($part)['text'] === '' || ++$n !== $item) {
                continue;
            }
            if (self::item($part)['text'] !== $text) {
                return null;
            }
            $parts[$k] = preg_match('/^(\s*)#\s*(.*)$/su', $part, $m) === 1 ? $m[1].$m[2] : (string) preg_replace('/^(\s*)/u', '$1#', $part, 1);

            return substr_replace($body, implode(',', $parts), $offset, strlen($inner));
        }

        return null;
    }

    /**
     * @return list<Item>
     */
    private static function items(string $inner): array
    {
        $items = array_map(self::item(...), explode(',', $inner));

        return array_values(array_filter($items, static fn (array $item): bool => $item['text'] !== ''));
    }

    /**
     * @return Item
     */
    private static function item(string $part): array
    {
        $part = trim($part);
        $done = str_starts_with($part, '#');

        return ['text' => $done ? trim(substr($part, 1)) : $part, 'done' => $done];
    }
}
