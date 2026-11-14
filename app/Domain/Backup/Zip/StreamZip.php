<?php

declare(strict_types=1);

namespace App\Domain\Backup\Zip;

use RuntimeException;

/**
 * A ZIP written straight to a stream (the browser), nothing on the server's disk (D-023). Entries are stored, not
 * compressed – photos and PDFs are compressed already – with a data descriptor behind each, so nothing has to be
 * known before it is sent. Classic ZIP without ZIP64: up to 4 GB and 65 535 entries; larger backups are built by
 * the background job (ArchiveZip) anyway.
 */
final class StreamZip implements ZipSink
{
    public const int MAX_ENTRIES = 65_535;

    public const int MAX_BYTES = 0xFFFFFFFF;

    private const int CHUNK = 1 << 20;

    private int $offset = 0;

    /** @var list<array{name: string, crc: int, size: int, offset: int}> */
    private array $entries = [];

    private readonly int $time;

    private readonly int $date;

    /**
     * @param  resource  $out
     */
    public function __construct(private $out)
    {
        $now = getdate();
        $this->time = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
        $this->date = (max(0, $now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];
    }

    public function addString(string $name, string $contents): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('No temporary stream.');
        }
        fwrite($stream, $contents);
        rewind($stream);
        try {
            return $this->addStream($name, $stream);
        } finally {
            fclose($stream);
        }
    }

    public function addFile(string $name, string $path): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Could not open {$name}.");
        }
        try {
            return $this->addStream($name, $stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     * @return array{int, string}
     */
    private function addStream(string $name, $stream): array
    {
        if (count($this->entries) >= self::MAX_ENTRIES) {
            throw new RuntimeException('Too many entries for a streamed ZIP.');
        }
        $offset = $this->offset;
        $this->write(pack('VvvvvvVVVvv', 0x04034B50, 20, 0x0808, 0, $this->time, $this->date, 0, 0, 0, strlen($name), 0).$name);

        $crc = hash_init('crc32b');
        $sha = hash_init('sha256');
        $size = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, self::CHUNK);
            if ($chunk === false) {
                throw new RuntimeException("Could not read {$name}.");
            }
            if ($chunk === '') {
                continue;
            }
            hash_update($crc, $chunk);
            hash_update($sha, $chunk);
            $size += strlen($chunk);
            $this->write($chunk);
        }
        $crcValue = (int) hexdec(hash_final($crc));
        $this->write(pack('VVVV', 0x08074B50, $crcValue, $size, $size));
        $this->entries[] = ['name' => $name, 'crc' => $crcValue, 'size' => $size, 'offset' => $offset];

        return [$size, hash_final($sha)];
    }

    public function finish(): void
    {
        $start = $this->offset;
        foreach ($this->entries as $entry) {
            $this->write(pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 0x0314, 20, 0x0808, 0, $this->time, $this->date,
                $entry['crc'], $entry['size'], $entry['size'], strlen($entry['name']), 0, 0, 0, 0, 0o100644 << 16, $entry['offset']).$entry['name']);
        }
        $count = count($this->entries);
        $this->write(pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, $this->offset - $start, $start, 0));
        fflush($this->out);
    }

    private function write(string $bytes): void
    {
        if ($this->offset + strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('A streamed ZIP stays below 4 GB.');
        }
        fwrite($this->out, $bytes);
        $this->offset += strlen($bytes);
    }
}
