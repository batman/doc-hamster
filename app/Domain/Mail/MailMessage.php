<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Point 48: one mail read from its raw text (RFC 5322 + MIME, RFC 2045–2047/2231) – sender, subject, date, the text
 * (plain, or the HTML part made plain) and the attached files. A mail forwarded as attachment (message/rfc822) is read
 * too: its files count as attachments, its text follows the mail's own. Pictures that only sit inside the HTML (logos,
 * signatures: a Content-ID, not marked as attachment) are not attachments.
 */
final class MailMessage
{
    /**
     * @param  array<string, list<string>>  $headers  lower-case name => raw values, topmost first
     * @param  list<array{name: string, type: string, bytes: string}>  $attachments
     */
    private function __construct(
        public readonly array $headers,
        public readonly string $from,
        public readonly string $fromName,
        public readonly string $to,
        public readonly string $subject,
        public readonly ?Carbon $date,
        public readonly string $text,
        public readonly array $attachments,
    ) {}

    public static function parse(string $raw): self
    {
        [$headers, $body] = self::split($raw);
        $parts = ['text' => '', 'html' => '', 'attachments' => [], 'forwarded' => []];
        self::walk($headers, $body, $parts, false);

        $text = trim($parts['text']) !== '' ? $parts['text'] : self::htmlToText($parts['html']);
        foreach ($parts['forwarded'] as $forwarded) {
            $text = rtrim($text)."\n\n".$forwarded;
        }
        [$from, $fromName] = self::address(self::header($headers, 'from'));
        $date = null;
        try {
            $value = self::header($headers, 'date');
            $date = $value !== '' ? Carbon::parse((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $value)) : null;
        } catch (Throwable) {
            $date = null;
        }

        return new self($headers, $from, $fromName, self::decode(self::header($headers, 'to')), self::decode(self::header($headers, 'subject')),
            $date, self::tidy($text), $parts['attachments']);
    }

    /** First value of a header, unfolded; '' when missing. */
    public function get(string $name): string
    {
        return self::header($this->headers, $name);
    }

    /**
     * A120: what the receiving server found about the sender. "fail" when the topmost Authentication-Results (or
     * Received-SPF) says the mail is forged – DMARC fail, or SPF fail without a valid DKIM signature; "pass" when
     * SPF, DKIM or DMARC passed; "none" without such a header (then the sender list alone decides).
     */
    public function authentication(): string
    {
        $results = mb_strtolower($this->get('authentication-results'));
        $spf = mb_strtolower($this->get('received-spf'));
        $dkimPass = str_contains($results, 'dkim=pass');
        if (str_contains($results, 'dmarc=fail')) {
            return 'fail';
        }
        $spfFail = preg_match('/spf=(soft)?fail/', $results) === 1 || preg_match('/^\s*(soft)?fail\b/', $spf) === 1;
        if ($spfFail && ! $dkimPass) {
            return 'fail';
        }
        if ($dkimPass || str_contains($results, 'spf=pass') || str_contains($results, 'dmarc=pass') || str_starts_with(trim($spf), 'pass')) {
            return 'pass';
        }

        return 'none';
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @param  array{text: string, html: string, attachments: list<array{name: string, type: string, bytes: string}>, forwarded: list<string>}  $parts
     */
    private static function walk(array $headers, string $body, array &$parts, bool $related): void
    {
        [$type, $params] = self::contentType(self::header($headers, 'content-type'));
        [$disposition, $dispositionParams] = self::contentType(self::header($headers, 'content-disposition'));

        if (str_starts_with($type, 'multipart/')) {
            $boundary = $params['boundary'] ?? '';
            if ($boundary === '') {
                return;
            }
            foreach (self::multipart($body, $boundary) as $part) {
                [$partHeaders, $partBody] = self::split($part);
                self::walk($partHeaders, $partBody, $parts, $related || $type === 'multipart/related');
            }

            return;
        }

        $content = self::transfer($body, mb_strtolower(trim(self::header($headers, 'content-transfer-encoding'))));
        $name = self::decode($dispositionParams['filename'] ?? $params['name'] ?? '');

        if ($type === 'message/rfc822') {
            // A mail forwarded as attachment: its files are attachments, its text is added below.
            $inner = self::parse($content);
            foreach ($inner->attachments as $attachment) {
                $parts['attachments'][] = $attachment;
            }
            $parts['forwarded'][] = implode("\n", array_filter([
                '---------- '.__('mail_inbox.pdf.forwarded').' ----------',
                $inner->from !== '' ? __('mail_inbox.pdf.from').': '.trim($inner->fromName.' <'.$inner->from.'>') : null,
                $inner->date !== null ? __('mail_inbox.pdf.date').': '.$inner->date->timezone(config('app.timezone'))->format('d.m.Y H:i') : null,
                $inner->subject !== '' ? __('mail_inbox.pdf.subject').': '.$inner->subject : null,
            ]))."\n\n".$inner->text;

            return;
        }

        $isAttachment = $disposition === 'attachment'
            || ($name !== '' && ! (($related || $disposition === 'inline') && self::header($headers, 'content-id') !== '' && str_starts_with($type, 'image/')));
        if ($isAttachment) {
            $parts['attachments'][] = ['name' => $name !== '' ? $name : 'anhang', 'type' => $type, 'bytes' => $content];

            return;
        }
        if ($type === 'text/plain' || $type === '') {
            $parts['text'] .= ($parts['text'] !== '' ? "\n\n" : '').self::utf8($content, $params['charset'] ?? '');
        } elseif ($type === 'text/html') {
            $parts['html'] .= self::utf8($content, $params['charset'] ?? '');
        }
    }

    /** @return array{array<string, list<string>>, string} headers and body */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $at = strpos($raw, "\n\n");
        $head = $at === false ? $raw : substr($raw, 0, $at);
        $body = $at === false ? '' : substr($raw, $at + 2);
        $headers = [];
        $head = (string) preg_replace('/\n[ \t]+/', ' ', $head);
        foreach (explode("\n", $head) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $headers[mb_strtolower(trim(substr($line, 0, $colon)))][] = trim(substr($line, $colon + 1));
        }

        return [$headers, $body];
    }

    /** @param  array<string, list<string>>  $headers */
    private static function header(array $headers, string $name): string
    {
        return $headers[$name][0] ?? '';
    }

    /** @return list<string> the parts between the boundaries */
    private static function multipart(string $body, string $boundary): array
    {
        $parts = [];
        $chunks = preg_split('/^--'.preg_quote($boundary, '/').'(--)?[ \t]*$/m', $body) ?: [];
        array_shift($chunks); // the preamble
        foreach ($chunks as $chunk) {
            if (trim($chunk) === '' && $parts !== []) {
                continue; // the epilogue
            }
            $parts[] = (string) preg_replace('/^\n|\n$/', '', $chunk);
        }

        return $parts;
    }

    /**
     * "text/plain; charset=utf-8; name*=UTF-8''Rechnung%20M%C3%A4rz.pdf" → [type, params], RFC 2231 continuations joined.
     *
     * @return array{string, array<string, string>}
     */
    private static function contentType(string $value): array
    {
        $pieces = self::splitParams($value);
        $type = mb_strtolower(trim((string) array_shift($pieces)));
        $params = [];
        $extended = [];
        foreach ($pieces as $piece) {
            $eq = strpos($piece, '=');
            if ($eq === false) {
                continue;
            }
            $key = mb_strtolower(trim(substr($piece, 0, $eq)));
            $val = trim(substr($piece, $eq + 1));
            if (str_starts_with($val, '"') && str_ends_with($val, '"') && strlen($val) >= 2) {
                $val = stripcslashes(substr($val, 1, -1));
            }
            if (preg_match('/^([a-z0-9_-]+)\*(\d+)?(\*)?$/', $key, $m) === 1) {
                $extended[$m[1]][(int) ($m[2] ?? 0)] = [$val, ($m[3] ?? '') === '*' || ! isset($m[2])];

                continue;
            }
            $params[$key] = $val;
        }
        foreach ($extended as $key => $chunks) {
            ksort($chunks);
            $charset = 'UTF-8';
            $joined = '';
            foreach ($chunks as $index => [$val, $encoded]) {
                if ($encoded) {
                    if ($index === 0 && preg_match("/^([^']*)'[^']*'(.*)$/", $val, $m) === 1) {
                        $charset = $m[1] !== '' ? $m[1] : 'UTF-8';
                        $val = $m[2];
                    }
                    $val = rawurldecode($val);
                }
                $joined .= $val;
            }
            $params[$key] = self::utf8($joined, $charset);
        }

        return [$type, $params];
    }

    /** @return list<string> split at semicolons outside quotes */
    private static function splitParams(string $value): array
    {
        $pieces = [];
        $current = '';
        $quoted = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '\\' && $quoted && $i + 1 < $length) {
                $current .= $char.$value[++$i];

                continue;
            }
            if ($char === '"') {
                $quoted = ! $quoted;
            }
            if ($char === ';' && ! $quoted) {
                $pieces[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }
        $pieces[] = $current;

        return $pieces;
    }

    private static function transfer(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode((string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $body)),
            'quoted-printable' => quoted_printable_decode(str_replace("\n", "\r\n", $body)),
            default => $body,
        };
    }

    private static function utf8(string $text, string $charset): string
    {
        $charset = strtoupper(trim($charset, " \"'"));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            return mb_scrub($text, 'UTF-8');
        }
        try {
            $converted = @mb_convert_encoding($text, 'UTF-8', $charset === 'ISO-8859-1' ? 'Windows-1252' : $charset);
        } catch (Throwable) {
            $converted = false;
        }

        return mb_scrub(is_string($converted) ? $converted : $text, 'UTF-8');
    }

    /** RFC 2047 encoded words ("=?UTF-8?Q?Rechnung_M=C3=A4rz?=") → UTF-8. */
    public static function decode(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return mb_scrub(trim($value), 'UTF-8');
        }
        $value = (string) preg_replace('/\?=\s+=\?/', '?==?', $value);
        $decoded = (string) preg_replace_callback('/=\?([^?]+)\?([bqBQ])\?([^?]*)\?=/', static function (array $m): string {
            $bytes = strtoupper($m[2]) === 'B' ? (string) base64_decode($m[3]) : quoted_printable_decode(str_replace('_', ' ', $m[3]));

            return self::utf8($bytes, (string) preg_replace('/\*.*$/', '', $m[1]));
        }, $value);

        return mb_scrub(trim($decoded), 'UTF-8');
    }

    /** @return array{string, string} address (lower case) and display name of "Max Mustermann <max@example.com>" */
    private static function address(string $value): array
    {
        $value = self::decode($value);
        if (preg_match('/^(.*)<([^>]+)>\s*$/s', $value, $m) === 1) {
            return [mb_strtolower(trim($m[2])), trim(trim($m[1]), '"\' ')];
        }

        return [mb_strtolower(trim($value, " <>\t")), ''];
    }

    /** The HTML part as readable text: paragraphs and line breaks stay, styles, scripts and images go. */
    public static function htmlToText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $html = (string) preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#<li\b[^>]*>#i', "\n• ", $html);
        $html = (string) preg_replace('#</(p|div|tr|li|h[1-6]|table|blockquote|ul|ol)\s*>#i', "\n", $html);
        $html = (string) preg_replace('#<(p|div|h[1-6]|table|blockquote)\b[^>]*>#i', "\n", $html);
        $html = (string) preg_replace('#</t[dh]\s*>#i', '   ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_replace("\u{00A0}", ' ', $text);
    }

    /** Lines trimmed, at most one empty line in a row. */
    private static function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_map(static fn (string $line): string => rtrim((string) preg_replace('/[ \t]+/', ' ', $line)), explode("\n", $text));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }
}
