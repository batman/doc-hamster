<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Banking\TransactionMatch;
use App\Domain\Org\BelongsToOrg;
use App\Domain\Properties\DocumentProperty;
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
 * @property bool $in_print_tray came from the printer "Hamster" (the source before the source column)
 * @property Carbon|null $inbox_done_at marked "Erledigt" in the Posteingang (point 153)
 * @property string|null $source upload|printer|mail|phone|api, null = upload (points 151/152)
 * @property Carbon $created_at
 * @property Carbon|null $last_opened_at
 * @property Carbon|null $last_downloaded_at
 */
final class Document extends Model
{
    use BelongsToOrg;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = ['org_id', 'folder_id', 'uploaded_by', 'title', 'doc_date', 'note', 'in_print_tray', 'inbox_done_at', 'source'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['doc_date' => 'date', 'last_opened_at' => 'datetime', 'last_downloaded_at' => 'datetime', 'in_print_tray' => 'boolean', 'inbox_done_at' => 'datetime'];
    }

    /**
     * Only what came from the printer "Hamster".
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeInPrintTray(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('in_print_tray'), true);
    }

    /**
     * Kept for callers: printed documents are ordinary documents now (point 153, the Posteingang replaced the
     * Druckerablage), so this no longer widens anything.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeWithPrintTray(Builder $query): Builder
    {
        return $query;
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
     * A340: what Chrome needs to drop the document's file onto the desktop ("mime:name:absolute url", DownloadURL);
     * only when the files are loaded already (no query per row).
     */
    public function dragFile(): ?string
    {
        $file = $this->relationLoaded('files') ? $this->files->first() : null;

        return $file instanceof DocumentFile
            ? $file->mime.':'.str_replace(':', '-', $file->downloadName($this->title)).':'.route('documents.download', $file->id)
            : null;
    }

    /**
     * @return HasMany<TransactionMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TransactionMatch::class);
    }

    /**
     * The property values (point 122.1), one per property.
     *
     * @return HasMany<DocumentProperty, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(DocumentProperty::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'document_tags')->orderBy('name');
    }

    /**
     * The date timeline and calendar go by (point 108): "doc" = the receipt date, the upload date when it has none;
     * "upload" = the upload date.
     */
    public function dateBy(string $basis): Carbon
    {
        return $basis === 'doc' && $this->doc_date !== null ? $this->doc_date : $this->created_at;
    }

    /**
     * The same date as SQL, as Y-m-d (for sorting, month bounds and the calendar range).
     *
     * @return literal-string
     */
    public static function dateSql(string $basis): string
    {
        return $basis === 'doc' ? 'DATE(COALESCE(doc_date, created_at))' : 'DATE(created_at)';
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
