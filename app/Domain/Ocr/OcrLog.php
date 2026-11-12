<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentFile;
use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The OCR log (point 61, A79-A83; point 69, A106-A112): one row per document and pass – file type, size, pages,
 * engine, duration, characters, result and a plain reason – plus system events (cron, mode switch, set-up,
 * "Alle neu erkennen"). Short messages only, never the recognised text. Kept 30 days.
 *
 * @property string $id
 * @property string $org_id
 * @property ?string $document_id
 * @property Carbon $created_at
 * @property string $source browser|app|system|server-setup
 * @property string $event
 * @property ?int $pass
 * @property ?int $duration_ms
 * @property ?int $chars
 * @property ?string $message
 * @property ?string $user_id
 * @property ?string $file_type
 * @property ?int $size
 * @property ?int $pages
 * @property ?string $agent
 */
final class OcrLog extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const array SOURCES = ['browser', 'app', 'system', 'server-setup'];

    /** Events shown as errors (red). */
    public const array ERRORS = ['timeout', 'failed', 'engine_failed', 'setup_failed', 'cron_missing'];

    /** A110: the result filter and the events behind each choice. */
    public const array RESULTS = [
        'recognized' => ['recognized', 'text_layer'],
        'failed' => ['failed', 'engine_failed', 'setup_failed'],
        'unavailable' => ['unavailable'],
        'timeout' => ['timeout'],
    ];

    /** Document events (one per document and pass); everything else is a system event. */
    public const array DOCUMENT_EVENTS = ['recognized', 'text_layer', 'failed', 'timeout', 'unavailable'];

    public const int KEEP_DAYS = 30;

    public const int PER_PAGE = 50;

    public $timestamps = false;

    protected $table = 'ocr_log';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'pass' => 'integer', 'duration_ms' => 'integer', 'chars' => 'integer', 'size' => 'integer', 'pages' => 'integer'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Writes one entry for the given organisation, whichever organisation is active (jobs, cron). Never throws:
     * the log must not break recognition. Now and then (1 %) it also drops old entries, so retention works without cron.
     *
     * @param  array{document_id?: ?string, pass?: ?int, duration_ms?: ?int, chars?: ?int, message?: ?string, user_id?: ?string, file_type?: ?string, size?: ?int, pages?: ?int, agent?: ?string}  $values
     */
    public static function record(string $orgId, string $source, string $event, array $values = []): void
    {
        try {
            $message = $values['message'] ?? null;
            DB::table('ocr_log')->insert([
                'id' => (string) Str::ulid(),
                'org_id' => $orgId,
                'document_id' => $values['document_id'] ?? null,
                'created_at' => now(),
                'source' => in_array($source, self::SOURCES, true) ? $source : 'system',
                'event' => Str::limit($event, 30, ''),
                'pass' => $values['pass'] ?? null,
                'duration_ms' => isset($values['duration_ms']) ? max(0, min(4_000_000_000, $values['duration_ms'])) : null,
                'chars' => isset($values['chars']) ? max(0, $values['chars']) : null,
                'message' => $message !== null && trim($message) !== '' ? Str::limit(trim($message), 290) : null,
                'user_id' => $values['user_id'] ?? null,
                'file_type' => isset($values['file_type']) ? Str::limit($values['file_type'], 10, '') : null,
                'size' => isset($values['size']) ? max(0, $values['size']) : null,
                'pages' => isset($values['pages']) ? max(0, min(65535, $values['pages'])) : null,
                'agent' => isset($values['agent']) ? Str::limit($values['agent'], 60, '') : null,
            ]);
            if (random_int(1, 100) === 1) {
                self::prune();
            }
        } catch (Throwable) {
            // Only a log.
        }
    }

    /**
     * A108: a document that cannot be read is logged with its reason – once per document and reason, not on every check.
     *
     * @param  array{document_id?: ?string, pass?: ?int, duration_ms?: ?int, chars?: ?int, message?: ?string, user_id?: ?string, file_type?: ?string, size?: ?int, pages?: ?int, agent?: ?string}  $values
     */
    public static function unavailable(string $orgId, string $documentId, string $source, string $reason, array $values = []): void
    {
        try {
            $known = DB::table('ocr_log')->where('document_id', $documentId)->where('event', 'unavailable')
                ->where('message', Str::limit($reason, 290))->exists();
        } catch (Throwable) {
            $known = false;
        }
        if (! $known) {
            self::record($orgId, $source, 'unavailable', [...$values, 'document_id' => $documentId, 'message' => $reason]);
        }
    }

    /**
     * A106: file type, size and pages of the file that was read.
     *
     * @return array{file_type?: string, size?: int, pages?: int}
     */
    public static function file(?DocumentFile $file, ?int $pages = null): array
    {
        if ($file === null) {
            return [];
        }

        return array_filter([
            'file_type' => self::type($file->mime),
            'size' => $file->size,
            'pages' => $pages ?? ($file->isImage() ? 1 : null),
        ], static fn ($v) => $v !== null);
    }

    /**
     * A translated log text (reasons, system messages) as a plain string.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    /** "application/pdf" → PDF, "image/jpeg" → JPEG. */
    public static function type(string $mime): string
    {
        $sub = strtolower(Str::after($mime, '/'));

        return match ($sub) {
            'jpg', 'pjpeg' => 'JPEG',
            'svg+xml' => 'SVG',
            'plain' => 'TXT',
            default => strtoupper(Str::limit(Str::before($sub, '+'), 10, '')),
        };
    }

    /** A109: a short browser name from the user agent, e.g. "Chrome 142 / Windows". */
    public static function agent(?string $userAgent): ?string
    {
        $ua = (string) $userAgent;
        if ($ua === '') {
            return null;
        }
        $browser = null;
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'FxiOS' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome' => 'Chrome'] as $token => $name) {
            if (preg_match('~'.$token.'/(\d+)~', $ua, $m) === 1) {
                $browser = $name.' '.$m[1];
                break;
            }
        }
        if ($browser === null && str_contains($ua, 'Safari') && preg_match('~Version/(\d+)~', $ua, $m) === 1) {
            $browser = 'Safari '.$m[1];
        }
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return implode(' / ', array_filter([$browser ?? Str::limit($ua, 30, ''), $os]));
    }

    /**
     * A112: the documents that are "nicht möglich" now, grouped by their latest logged reason, most first.
     *
     * @return array<string, int> reason => count ('' = no reason logged)
     */
    public static function unavailableSummary(string $orgId): array
    {
        $documents = DB::table('documents')->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('ocr_status', 'unavailable')->pluck('id')->all();
        if ($documents === []) {
            return [];
        }
        $reasons = [];
        foreach (array_chunk($documents, 500) as $chunk) {
            DB::table('ocr_log')->where('org_id', $orgId)->where('event', 'unavailable')->whereIn('document_id', $chunk)
                ->orderBy('created_at')->orderBy('id')->select(['document_id', 'message'])
                ->get()->each(static function (object $row) use (&$reasons): void {
                    $reasons[(string) $row->document_id] = (string) ($row->message ?? '');
                });
        }
        $summary = [];
        foreach ($documents as $id) {
            $reason = $reasons[(string) $id] ?? '';
            $summary[$reason] = ($summary[$reason] ?? 0) + 1;
        }
        arsort($summary);

        return $summary;
    }

    /** Drops entries older than 30 days (daily schedule and now and then on write). */
    public static function prune(): int
    {
        return DB::table('ocr_log')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }
}
