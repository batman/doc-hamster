<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Banking\TransactionMatch;
use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A receipt. Org-scoped; who may see which one is PLAN §2.1 (see visibleTo()).
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $folder_id
 * @property string|null $uploaded_by
 * @property string $title
 * @property Carbon|null $doc_date
 * @property string|null $note
 * @property bool $in_print_tray
 * @property Carbon $created_at
 * @property Carbon|null $last_opened_at
 * @property Carbon|null $last_downloaded_at
 */
final class Document extends Model
{
    use BelongsToOrg;
    use HasUlids;
    use SoftDeletes;

    /** Global scope: printed documents are not receipts until someone takes them over. */
    public const OUTSIDE_PRINT_TRAY = 'outside_print_tray';

    protected $fillable = ['org_id', 'folder_id', 'uploaded_by', 'title', 'doc_date', 'note', 'in_print_tray'];

    protected static function booted(): void
    {
        self::addGlobalScope(self::OUTSIDE_PRINT_TRAY, static function (Builder $query): void {
            $query->where($query->qualifyColumn('in_print_tray'), false);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['doc_date' => 'date', 'last_opened_at' => 'datetime', 'last_downloaded_at' => 'datetime', 'in_print_tray' => 'boolean'];
    }

    /**
     * Only what waits in the print tray (Druckerablage).
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeInPrintTray(Builder $query): Builder
    {
        return $query->withoutGlobalScope(self::OUTSIDE_PRINT_TRAY)->where($query->qualifyColumn('in_print_tray'), true);
    }

    /**
     * Receipts and print tray together (trash, file delivery).
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeWithPrintTray(Builder $query): Builder
    {
        return $query->withoutGlobalScope(self::OUTSIDE_PRINT_TRAY);
    }

    /**
     * Receipts the bank matching may use: those in a folder with matching on
     * (PO, 2026-11-06). Documents outside any folder (old data) still count.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeMatchable(Builder $query): Builder
    {
        return $query->where(static fn (Builder $inner): Builder => $inner
            ->whereNull($query->qualifyColumn('folder_id'))
            ->orWhereIn($query->qualifyColumn('folder_id'), Folder::query()->where('bank_matching', true)->select('id')));
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /**
     * @return HasMany<DocumentFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(DocumentFile::class);
    }

    /**
     * @return HasMany<TransactionMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TransactionMatch::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'document_tags')->orderBy('name');
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
