<?php

declare(strict_types=1);

namespace App\Jobs\Net;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Prints on a network printer over IPP (RFC 8010/8011, "IPP Everywhere"),
 * the open protocol phones and computers use without drivers. Written from
 * the public RFCs; no vendor software.
 *
 * The printer is the same device as the scanner (D-020, App\Jobs\Net): its
 * address comes from ScannerAddress::normalise(), so it is on the private
 * network. Runs synchronously when a person taps "Drucken". Errors are
 * RuntimeExceptions with a translation key `print.*`.
 */
final class IppClient
{
    private const GET_PRINTER_ATTRIBUTES = 0x000B;

    private const PRINT_JOB = 0x0002;

    /** Delimiter and value tags (RFC 8010 §3.5). */
    private const OPERATION = 0x01;

    private const END = 0x03;

    private const KEYWORD = 0x44;

    private const URI = 0x45;

    private const CHARSET = 0x47;

    private const LANGUAGE = 0x48;

    private const MIME = 0x49;

    private const NAME = 0x42;

    /** @param  string  $base  scheme://host[:port] of the device (the scanner address) */
    public function __construct(private readonly string $base) {}

    /** Sends one document; picks a format the printer takes (PDF, JPEG, or its own detection). */
    public function print(string $bytes, string $mime, string $title): void
    {
        $formats = $this->formats();
        if (! in_array($mime, $formats, true)) {
            if ($mime !== 'image/jpeg' && str_starts_with($mime, 'image/') && in_array('image/jpeg', $formats, true)) {
                [$bytes, $mime] = [self::toJpeg($bytes), 'image/jpeg'];
            } elseif (in_array('application/octet-stream', $formats, true)) {
                $mime = 'application/octet-stream';
            } else {
                throw new RuntimeException('print.format_unsupported');
            }
        }

        $answer = $this->call(self::PRINT_JOB, [
            [self::NAME, 'job-name', mb_substr($title, 0, 200)],
            [self::MIME, 'document-format', $mime],
        ], $bytes);
        if ($answer['status'] >= 0x0100) {
            throw new RuntimeException($answer['status'] === 0x040A ? 'print.format_unsupported' : 'print.refused');
        }
    }

    /**
     * The document formats the printer accepts.
     *
     * @return list<string>
     */
    public function formats(): array
    {
        $answer = $this->call(self::GET_PRINTER_ATTRIBUTES, [[self::KEYWORD, 'requested-attributes', 'document-format-supported']]);
        if ($answer['status'] >= 0x0100) {
            throw new RuntimeException('print.not_a_printer');
        }

        return $answer['attributes']['document-format-supported'] ?? [];
    }

    /**
     * Encodes an IPP request (RFC 8010 §3.1).
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $attributes  operation attributes after the required ones
     */
    public static function encode(int $operation, string $printerUri, array $attributes, int $requestId = 1): string
    {
        $all = [
            [self::CHARSET, 'attributes-charset', 'utf-8'],
            [self::LANGUAGE, 'attributes-natural-language', 'en'],
            [self::URI, 'printer-uri', $printerUri],
            [self::NAME, 'requesting-user-name', 'DocHamster'],
            ...$attributes,
        ];
        $out = pack('CCnN', 2, 0, $operation, $requestId).chr(self::OPERATION);
        foreach ($all as [$tag, $name, $value]) {
            $out .= chr($tag).pack('n', strlen($name)).$name.pack('n', strlen($value)).$value;
        }

        return $out.chr(self::END);
    }

    /**
     * Reads an IPP response: the status code and every attribute as a list of string values.
     *
     * @return array{status: int, attributes: array<string, list<string>>}
     */
    public static function decode(string $body): array
    {
        if (strlen($body) < 9) {
            throw new RuntimeException('print.not_a_printer');
        }
        $status = unpack('n', substr($body, 2, 2))[1] ?? 0xFFFF;
        $attributes = [];
        $last = null;
        $at = 8;
        $length = strlen($body);
        while ($at < $length) {
            $tag = ord($body[$at++]);
            if ($tag === self::END) {
                break;
            }
            if ($tag < 0x10) {
                continue; // the start of the next group
            }
            if ($at + 2 > $length) {
                break;
            }
            $nameLength = unpack('n', substr($body, $at, 2))[1] ?? 0;
            $name = substr($body, $at + 2, $nameLength);
            $at += 2 + $nameLength;
            if ($at + 2 > $length) {
                break;
            }
            $valueLength = unpack('n', substr($body, $at, 2))[1] ?? 0;
            $value = substr($body, $at + 2, $valueLength);
            $at += 2 + $valueLength;

            // An empty name is one more value of the attribute before.
            $key = $nameLength > 0 ? $name : $last;
            if ($key === null) {
                continue;
            }
            $last = $key;
            $attributes[$key][] = $value;
        }

        return ['status' => (int) $status, 'attributes' => $attributes];
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string}>  $attributes
     * @return array{status: int, attributes: array<string, list<string>>}
     */
    private function call(int $operation, array $attributes, string $document = ''): array
    {
        $host = (string) parse_url($this->base, PHP_URL_HOST);
        // The usual IPP place first; some devices only answer over HTTPS on their web port.
        $tries = [['http://'.$host.':631/ipp/print', 'ipp://'.$host.':631/ipp/print'], ['https://'.$host.'/ipp/print', 'ipps://'.$host.'/ipp/print']];
        foreach ($tries as [$url, $uri]) {
            try {
                $response = $this->post($url, self::encode($operation, $uri, $attributes).$document);
            } catch (ConnectionException) {
                continue;
            }
            if ($response->successful() && str_starts_with((string) $response->header('Content-Type'), 'application/ipp')) {
                return self::decode($response->body());
            }
        }

        throw new RuntimeException('print.unreachable');
    }

    private function post(string $url, string $body): Response
    {
        return Http::withoutVerifying()->withUserAgent('DocHamster')->connectTimeout(5)->timeout(120)->withoutRedirecting()
            ->withBody($body, 'application/ipp')->post($url);
    }

    private static function toJpeg(string $bytes): string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RuntimeException('print.format_unsupported');
        }
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }
}
