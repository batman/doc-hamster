<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

use App\Domain\Documents\Document;
use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The OCR log (point 61, A79-A83): one row per thing text recognition did – a document read, a time limit, an
 * error, the browser engine starting or failing, pause/resume, the server set-up, cron going missing or coming
 * back. Short messages only, never the recognised text. Kept 30 days.
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
 */
final class OcrLog extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const array SOURCES = ['browser', 'app', 'system', 'server-setup'];

    /** Events that count as errors for the "nur Fehler" filter. */
    public const array ERRORS = ['timeout', 'failed', 'engine_failed', 'setup_failed', 'cron_missing'];

    public const int KEEP_DAYS = 30;

    public const int PER_PAGE = 50;

    public $timestamps = false;

    protected $table = 'ocr_log';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'pass' => 'integer', 'duration_ms' => 'integer', 'chars' => 'integer'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Writes one entry for the given organisation, whichever organisation is active (jobs, cron). Never throws:
     * the log must not break recognition. Now and then (1 %) it also drops old entries, so retention works without cron.
     *
     * @param  array{document_id?: ?string, pass?: ?int, duration_ms?: ?int, chars?: ?int, message?: ?string, user_id?: ?string}  $values
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
                'message' => $message !== null ? Str::limit($message, 290) : null,
                'user_id' => $values['user_id'] ?? null,
            ]);
            if (random_int(1, 100) === 1) {
                self::prune();
            }
        } catch (Throwable) {
            // Only a log.
        }
    }

    /** Drops entries older than 30 days (daily schedule and now and then on write). */
    public static function prune(): int
    {
        return DB::table('ocr_log')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }
}
