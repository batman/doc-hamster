<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * What a phone shares (points 151, 152, A328): every file is stored at once like an upload; when only
 * text or a link comes, a small PDF is made from it. Used by the share target (/share) and the upload
 * API of the device keys.
 */
final class ShareIntake
{
    /**
     * @param  list<mixed>  $files
     * @return array{documents: list<Document>, skipped: int}
     */
    public static function store(array $files, ?string $title, ?string $text, ?string $url, string $orgId, string $userId, string $source): array
    {
        $documents = [];
        $skipped = 0;
        $given = 0;
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() === 0) {
                continue;
            }
            $given++;
            $type = DocumentIntake::detect($file);
            $name = trim($file->getClientOriginalName());
            if ($type === null && self::isText($file)) {
                // A shared text file (or a link an iPhone shortcut sent as file) becomes a PDF as well.
                $documents[] = self::storeText(pathinfo($name, PATHINFO_FILENAME) ?: self::fallbackTitle(), self::clean((string) file_get_contents($file->getRealPath()), true), $orgId, $userId, $source);

                continue;
            }
            if ($type === null) {
                $skipped++;

                continue;
            }
            $documents[] = DocumentIntake::store($file, $type[0], $type[1], $orgId, $userId, $name !== '' ? $name : self::fallbackTitle(), source: $source);
        }

        // A328: nothing but text or a link → a small PDF document.
        $shared = $given === 0 ? self::textOf($title, $text, $url) : null;
        if ($shared !== null) {
            $documents[] = self::storeText($shared['title'], $shared['body'], $orgId, $userId, $source);
        }

        return ['documents' => $documents, 'skipped' => $skipped];
    }

    /**
     * Title and text of a text-only share; null when there is nothing. Android often puts the link into
     * `text`; the link is added once. The title is the shared title, else the first line (a lone link:
     * its host).
     *
     * @return array{title: string, body: string}|null
     */
    public static function textOf(?string $title, ?string $text, ?string $url): ?array
    {
        $title = self::clean($title, false);
        $text = self::clean($text, true);
        $url = self::clean($url, false);
        if ($text === '' && $url === '') {
            return null;
        }

        $body = $text;
        if ($url !== '' && ! str_contains($text, $url)) {
            $body = $text === '' ? $url : $text."\n\n".$url;
        }

        if ($title === '') {
            $first = trim(strtok($body, "\n") ?: '');
            $host = preg_match('~^https?://\S+$~i', $first) === 1 ? parse_url($first, PHP_URL_HOST) : null;
            $title = is_string($host) && $host !== '' ? $host : $first;
        }

        return ['title' => Str::limit($title !== '' ? $title : self::fallbackTitle(), 120, '…'), 'body' => $body];
    }

    public static function storeText(string $title, string $body, string $orgId, string $userId, string $source): Document
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dhshare');
        file_put_contents($path, TextPdf::make($title, $body));
        try {
            $upload = new UploadedFile($path, Str::slug($title) !== '' ? Str::slug($title).'.pdf' : 'text.pdf', 'application/pdf', null, true);

            return DocumentIntake::store($upload, 'application/pdf', 'pdf', $orgId, $userId, $title, source: $source);
        } finally {
            @unlink($path);
        }
    }

    private static function isText(UploadedFile $file): bool
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());

        return $file->getSize() <= 1048576 && in_array($mime, ['text/plain', 'text/x-uri', 'text/uri-list'], true);
    }

    private static function clean(?string $value, bool $multiline): string
    {
        $value = mb_scrub((string) $value, 'UTF-8');
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace($multiline ? '/[\x00-\x08\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]+/', $multiline ? '' : ' ', $value);

        return trim(Str::limit($value, $multiline ? 100000 : 2000, ''));
    }

    private static function fallbackTitle(): string
    {
        return __('share.default_title', ['date' => now()->format('d.m.Y H:i')]);
    }
}
