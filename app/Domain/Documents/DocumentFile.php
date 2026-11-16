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
 * @property string|null $transform point 238: rotation/crop per page, JSON (PageTransform)
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

    /** The preview's URL; its path changes with a new rotation/crop, so the long browser cache never shows an old one. */
    public function thumbUrl(): string
    {
        return route('documents.thumb', ['id' => $this->id, 'v' => substr(md5((string) $this->thumb_path), 0, 8)]);
    }

    /** Point 238 (A416): the rotation/crop of one page of this file (an image has page 1 only). */
    public function pageTransform(int $page = 1): PageTransform
    {
        return PageTransform::fromArray($this->transforms()[$page] ?? null);
    }

    /**
     * The stored transforms by page number (raw form, see PageTransform::fromArray).
     *
     * @return array<int, mixed>
     */
    public function transforms(): array
    {
        $raw = $this->getAttribute('transform');
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $out = [];
        foreach (is_array($data) ? $data : [] as $page => $value) {
            if (is_numeric($page) && (int) $page >= 1) {
                $out[(int) $page] = $value;
            }
        }

        return $out;
    }

    /** Sets (not saves) the transform of one page; no transform on any page leaves the column empty. */
    public function setPageTransform(int $page, PageTransform $transform): void
    {
        $all = $this->transforms();
        $value = $transform->toArray();
        if ($value === null) {
            unset($all[$page]);
        } else {
            $all[$page] = $value;
        }
        ksort($all);
        $this->forceFill(['transform' => $all === [] ? null : json_encode((object) $all)]);
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
