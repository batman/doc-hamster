<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 */
final class DocumentFile extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'document_id', 'kind', 'page_no', 'path', 'mime', 'size', 'sha256'];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
