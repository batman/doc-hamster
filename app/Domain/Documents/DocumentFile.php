<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A stored file of a document, on the private disk outside the web root.
 *
 * @property string $id
 * @property string $org_id
 * @property string $document_id
 * @property string $kind
 * @property int $page_no
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string|null $thumb_path
 */
final class DocumentFile extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'document_id', 'kind', 'page_no', 'path', 'mime', 'size', 'sha256', 'thumb_path'];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** Tabler icon for the file type in lists (design point 13). */
    public function typeIcon(): string
    {
        return match (true) {
            $this->isImage() => 'photo',
            $this->mime === 'application/pdf' => 'file-type-pdf',
            default => 'file-text',
        };
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * The name a download gets: the title people gave it, with the file type
     * behind it. Slashes are path separators in a download header, so a
     * title like "Rechnung 03/2026" becomes "Rechnung 03-2026" (#56).
     */
    public function downloadName(string $title): string
    {
        $name = trim((string) preg_replace('/[\/\\\\\x00-\x1F\x7F]+/', '-', $title));
        $name = $name === '' ? 'beleg' : $name;
        $extension = Str::lower(pathinfo($this->path, PATHINFO_EXTENSION));

        return $extension === '' || str_ends_with(Str::lower($name), '.'.$extension) ? $name : $name.'.'.$extension;
    }
}
