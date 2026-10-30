<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A receipt. Org-scoped; who may see which one is PLAN §2.1 (see visibleTo()).
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $uploaded_by
 * @property string $title
 * @property Carbon|null $doc_date
 * @property string|null $note
 * @property Carbon $created_at
 */
final class Document extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'uploaded_by', 'title', 'doc_date', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['doc_date' => 'date'];
    }

    /**
     * @return HasMany<DocumentFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(DocumentFile::class);
    }

    /**
     * Everything for those who may see all documents, otherwise only the user's own.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeVisibleTo(Builder $query, string $userId, bool $seesAll): Builder
    {
        return $seesAll ? $query : $query->where('uploaded_by', $userId);
    }
}
