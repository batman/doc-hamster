<?php

declare(strict_types=1);

namespace App\Domain\Log;

use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Einstellungen → Log (point 115, A229): one entry of the logbooks "Allgemein" (events like signing in or uploading, and
 * every error of the server or the browser) and "Updates". The logbook "Texterkennung" lives in ocr_log (OcrLog) and is
 * shown in the same list. Every entry has a level: event, warning or error. Short texts only, never secrets.
 *
 * @property string $id
 * @property ?string $org_id
 * @property Carbon $created_at
 * @property string $book
 * @property string $level
 * @property ?string $event
 * @property string $message
 * @property ?string $user_id
 * @property ?string $document_id
 */
final class LogEntry extends Model
{
    use HasUlids;

    /** The logbooks, in menu order (A229). "ocr" is stored in ocr_log. */
    public const array BOOKS = ['general', 'ocr', 'updates'];

    public const array LEVELS = ['event', 'warning', 'error'];

    public const int KEEP_MONTHS = 12;

    public const int PER_PAGE = 50;

    public $timestamps = false;

    protected $table = 'log_entries';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Writes one entry. Never throws – a log must not break what it logs. Organisation and person default to the
     * current ones. Now and then (1 %) old entries go (A228), so retention works without cron too.
     */
    public static function write(string $book, string $level, string $message, ?string $event = null, ?string $orgId = null, ?string $userId = null, ?string $documentId = null): void
    {
        try {
            if ($orgId === null) {
                $org = app(CurrentOrg::class);
                $orgId = $org->isSet() ? $org->id() : null;
            }
            $userId ??= Auth::id();
            DB::table('log_entries')->insert([
                'id' => (string) Str::ulid(),
                'org_id' => $orgId,
                'created_at' => now(),
                'book' => in_array($book, self::BOOKS, true) && $book !== 'ocr' ? $book : 'general',
                'level' => in_array($level, self::LEVELS, true) ? $level : 'event',
                'event' => $event !== null ? Str::limit($event, 64, '') : null,
                'message' => Str::limit(trim($message) !== '' ? trim($message) : '?', 990),
                'user_id' => is_string($userId) ? $userId : null,
                'document_id' => $documentId,
            ]);
            if (random_int(1, 100) === 1) {
                self::prune();
            }
        } catch (Throwable) {
            // Only a log.
        }
    }

    public static function prune(): void
    {
        DB::table('log_entries')->where('created_at', '<', now()->subMonths(self::KEEP_MONTHS))->delete();
    }
}
