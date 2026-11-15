<?php

declare(strict_types=1);

namespace App\Domain\Optimize\Pdf;

use RuntimeException;

/**
 * Point 236: the objects of one PDF, found by their "n g obj" headers (no trust in the cross-reference table) plus
 * those packed in object streams. Strict on purpose: an object number that turns up twice (incremental updates, as
 * signatures and edits leave them), an encrypted file or a stream filter it doesn't know make open() throw â€“ the
 * optimiser then leaves the file as it is.
 */
final class PdfFile
{
    /** @var array<int, int> object number => byte offset of its value */
    private array $offsets = [];

    /** @var array<int, array{string, int}> object number => [unpacked object stream, offset of its value] */
    private array $packed = [];

    /** @var list<string> the unpacked object streams (they are searched for forbidden keys too) */
    private array $unpacked = [];

    /** @var list<array{int, int}> byte ranges of stream bodies (with their line break) */
    private array $streams = [];

    private readonly PdfSyntax $syntax;

    private function __construct(public readonly string $bytes)
    {
        $this->syntax = new PdfSyntax($bytes);
    }

    public static function open(string $bytes): self
    {
        if (! str_starts_with(ltrim(substr($bytes, 0, 1024)), '%PDF-')) {
            throw new RuntimeException('no pdf');
        }
        $file = new self($bytes);
        if (preg_match_all('/(?:^|[\r\n])[ \t]*(\d+)[ \t\r\n]+(\d+)[ \t\r\n]+obj\b/', $bytes, $m, PREG_OFFSET_CAPTURE) < 1) {
            throw new RuntimeException('no objects');
        }
        foreach ($m[1] as $i => [$num]) {
            $num = (int) $num;
            if (isset($file->offsets[$num])) {
                throw new RuntimeException('object '.$num.' twice');
            }
            $file->offsets[$num] = (int) $m[0][$i][1] + strlen($m[0][$i][0]);
        }
        $file->scan();

        return $file;
    }

    /**
     * The bytes to search for forbidden keys: the file without the stream bodies (pictures are binary and would give
     * false hits), XMP metadata kept, plus every unpacked object stream.
     */
    public function searchable(): string
    {
        $parts = [];
        $at = 0;
        foreach ($this->streams as [$start, $end]) {
            $parts[] = substr($this->bytes, $at, $start - $at);
            $at = $end;
        }
        $parts[] = substr($this->bytes, $at);

        return implode("\n", [...$parts, ...$this->unpacked]);
    }

    /** The trailer's /Root (classic trailer or cross-reference stream; the last one names it). */
    public function rootRef(): int
    {
        if (preg_match_all('/\/Root[\x00\t\n\x0C\r ]+(\d+)[\x00\t\n\x0C\r ]+\d+[\x00\t\n\x0C\r ]+R/', $this->bytes, $m) < 1) {
            throw new RuntimeException('no root');
        }

        return (int) end($m[1]);
    }

    public function get(int $num): mixed
    {
        if (isset($this->packed[$num])) {
            [$content, $at] = $this->packed[$num];

            return (new PdfSyntax($content))->value($at);
        }
        if (! isset($this->offsets[$num])) {
            throw new RuntimeException('object '.$num.' missing');
        }
        $pos = $this->offsets[$num];

        return $this->syntax->value($pos);
    }

    /** Follows references until a direct value. */
    public function resolve(mixed $value): mixed
    {
        for ($i = 0; $value instanceof PdfRef; $i++) {
            if ($i > 16) {
                throw new RuntimeException('reference loop');
            }
            $value = $this->get($value->num);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function dict(mixed $value): array
    {
        $value = $this->resolve($value);
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('dictionary expected');
        }

        return $value;
    }

    /**
     * The raw (still encoded) bytes of a stream object and its dictionary.
     *
     * @return array{array<string, mixed>, string}
     */
    public function stream(PdfRef $ref): array
    {
        if (! isset($this->offsets[$ref->num])) {
            throw new RuntimeException('stream '.$ref->num.' missing');
        }
        $pos = $this->offsets[$ref->num];
        $dict = $this->syntax->value($pos);
        if (! is_array($dict) || ! $this->syntax->keyword($pos, 'stream')) {
            throw new RuntimeException('no stream');
        }
        $pos += substr($this->bytes, $pos, 2) === "\r\n" ? 2 : 1;
        $length = $this->resolve($dict['/Length'] ?? null);
        if (! is_int($length) || $length < 0 || $pos + $length > strlen($this->bytes)) {
            throw new RuntimeException('stream length');
        }

        return [$dict, substr($this->bytes, $pos, $length)];
    }

    /** A stream's content with its filter undone: none or FlateDecode without predictor only. */
    public function decoded(PdfRef $ref): string
    {
        [$dict, $raw] = $this->stream($ref);
        $filter = $this->resolve($dict['/Filter'] ?? null);
        $filter = is_array($filter) && count($filter) === 1 ? $this->resolve($filter[0]) : $filter;
        if ($filter === null) {
            return $raw;
        }
        if ($filter !== '/FlateDecode' || isset($dict['/DecodeParms'])) {
            throw new RuntimeException('filter');
        }
        $out = @gzuncompress($raw);
        if ($out === false) {
            $out = @gzinflate(substr($raw, 2));
        }
        if ($out === false) {
            throw new RuntimeException('flate');
        }

        return $out;
    }

    /**
     * Reads every object's head once: notes where stream bodies lie (not searched, except XMP metadata) and unpacks
     * /Type /ObjStm streams (PDF 1.5+), each object number at most once overall.
     */
    private function scan(): void
    {
        $heads = [];
        foreach ($this->offsets as $num => $pos) {
            $head = $this->syntax->value($pos);
            if (is_array($head) && $this->syntax->keyword($pos, 'stream')) {
                $heads[$num] = [$head, $pos];
            }
        }
        foreach ($heads as $num => [$head]) {
            if (($head['/Type'] ?? null) === '/ObjStm') {
                $this->unpack($num, $head);
            }
        }
        foreach ($heads as [$head, $pos]) {
            $length = $this->resolve($head['/Length'] ?? null);
            if (! is_int($length) || $length < 0) {
                throw new RuntimeException('stream length');
            }
            if (($head['/Type'] ?? null) !== '/Metadata') {
                $this->streams[] = [$pos, min(strlen($this->bytes), $pos + 2 + $length)];
            }
        }
        usort($this->streams, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    }

    /** @param  array<string, mixed>  $head */
    private function unpack(int $num, array $head): void
    {
        $content = $this->decoded(new PdfRef($num));
        $count = $this->resolve($head['/N'] ?? null);
        $first = $this->resolve($head['/First'] ?? null);
        if (! is_int($count) || ! is_int($first) || $count < 0) {
            throw new RuntimeException('object stream');
        }
        $numbers = preg_split('/[\x00\t\n\x0C\r ]+/', trim(substr($content, 0, $first)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($numbers) !== 2 * $count) {
            throw new RuntimeException('object stream header');
        }
        $this->unpacked[] = $content;
        for ($i = 0; $i < $count; $i++) {
            $inner = (int) $numbers[2 * $i];
            if (isset($this->offsets[$inner]) || isset($this->packed[$inner])) {
                throw new RuntimeException('object '.$inner.' twice');
            }
            $this->packed[$inner] = [$content, $first + (int) $numbers[2 * $i + 1]];
        }
    }
}
