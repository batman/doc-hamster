<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A link that hands out one receipt without login (A4). Only the hash of the
 * token is stored, so the database alone can't open a receipt.
 *
 * @property string $id
 * @property string $org_id
 * @property string $document_id
 * @property string|null $created_by
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property-read Document $document
 */
final class ShareLink extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const DEFAULT_DAYS = 7;

    protected $fillable = ['org_id', 'document_id', 'created_by', 'token_hash', 'expires_at', 'revoked_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return array{self, string} the link and its token, which only exists in this moment
     */
    public static function issue(Document $document, string $userId, int $days): array
    {
        $token = Str::random(40);
        $link = self::query()->create([
            'document_id' => $document->id,
            'created_by' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays($days),
        ]);

        return [$link, $token];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
