<?php

declare(strict_types=1);

namespace App\Domain\Optimize\Pdf;

use RuntimeException;

/**
 * Point 236: reads one PDF value at a byte position – just enough syntax to look at a scan: dictionaries (PHP arrays
 * keyed "/Name"), arrays (lists), names ("/Name" strings), numbers, references (PdfRef), strings (PdfString),
 * true/false/null. Anything it can't read throws; the caller then leaves the file alone.
 */
final class PdfSyntax
{
    private const string SPACE = "\x00\t\n\x0C\r ";

    private const string DELIMITERS = '()<>[]{}/%';

    private int $depth = 0;

    public function __construct(private readonly string $bytes) {}

    public function value(int &$pos): mixed
    {
        $this->skip($pos);
        $c = $this->bytes[$pos] ?? '';
        if ($c === '') {
            throw new RuntimeException('end of file');
        }
        if (++$this->depth > 64) {
            throw new RuntimeException('too deep');
        }
        try {
            return match (true) {
                $c === '<' && ($this->bytes[$pos + 1] ?? '') === '<' => $this->dict($pos),
                $c === '<' => $this->hex($pos),
                $c === '[' => $this->list($pos),
                $c === '(' => $this->literal($pos),
                $c === '/' => $this->name($pos),
                default => $this->word($pos),
            };
        } finally {
            $this->depth--;
        }
    }

    /** Skips white space and comments. */
    public function skip(int &$pos): void
    {
        $length = strlen($this->bytes);
        while ($pos < $length) {
            $c = $this->bytes[$pos];
            if ($c === '%') {
                $pos += strcspn($this->bytes, "\r\n", $pos);

                continue;
            }
            if (! str_contains(self::SPACE, $c)) {
                return;
            }
            $pos++;
        }
    }

    /** Whether the text at $pos (after white space) starts with $word; moves past it if so. */
    public function keyword(int &$pos, string $word): bool
    {
        $at = $pos;
        $this->skip($at);
        if (substr($this->bytes, $at, strlen($word)) !== $word) {
            return false;
        }
        $pos = $at + strlen($word);

        return true;
    }

    /** @return array<string, mixed> */
    private function dict(int &$pos): array
    {
        $pos += 2;
        $dict = [];
        while (true) {
            $this->skip($pos);
            if (substr($this->bytes, $pos, 2) === '>>') {
                $pos += 2;

                return $dict;
            }
            $key = $this->value($pos);
            if (! is_string($key)) {
                throw new RuntimeException('dictionary key');
            }
            $dict[$key] = $this->value($pos);
        }
    }

    /** @return list<mixed> */
    private function list(int &$pos): array
    {
        $pos++;
        $list = [];
        while (true) {
            $this->skip($pos);
            if (($this->bytes[$pos] ?? '') === ']') {
                $pos++;

                return $list;
            }
            $list[] = $this->value($pos);
        }
    }

    private function name(int &$pos): string
    {
        $start = $pos++;
        $pos += strcspn($this->bytes, self::SPACE.self::DELIMITERS, $pos);

        return substr($this->bytes, $start, $pos - $start);
    }

    private function hex(int &$pos): PdfString
    {
        $end = strpos($this->bytes, '>', $pos);
        if ($end === false) {
            throw new RuntimeException('hex string');
        }
        $raw = substr($this->bytes, $pos + 1, $end - $pos - 1);
        $pos = $end + 1;

        return new PdfString($raw);
    }

    private function literal(int &$pos): PdfString
    {
        $start = ++$pos;
        $open = 1;
        $length = strlen($this->bytes);
        while ($pos < $length) {
            $c = $this->bytes[$pos++];
            if ($c === '\\') {
                $pos++;
            } elseif ($c === '(') {
                $open++;
            } elseif ($c === ')' && --$open === 0) {
                return new PdfString(substr($this->bytes, $start, $pos - $start - 1));
            }
        }
        throw new RuntimeException('string');
    }

    /** A number, a reference "n g R", true, false or null. */
    private function word(int &$pos): mixed
    {
        $start = $pos;
        $pos += strcspn($this->bytes, self::SPACE.self::DELIMITERS, $pos);
        $word = substr($this->bytes, $start, $pos - $start);
        if ($word === '') {
            throw new RuntimeException('unexpected '.($this->bytes[$pos] ?? 'end'));
        }
        if (preg_match('/^\d+$/', $word) === 1) {
            if (preg_match('/\G[\x00\t\n\x0C\r ]+(\d+)[\x00\t\n\x0C\r ]+R(?![^\x00\t\n\x0C\r ()<>\[\]{}\/%])/', $this->bytes, $m, 0, $pos) === 1) {
                $pos += strlen($m[0]);

                return new PdfRef((int) $word, (int) $m[1]);
            }

            return (int) $word;
        }

        return match (true) {
            $word === 'true' => true,
            $word === 'false' => false,
            $word === 'null' => null,
            is_numeric($word) => (float) $word,
            default => throw new RuntimeException('word '.substr($word, 0, 20)),
        };
    }
}
