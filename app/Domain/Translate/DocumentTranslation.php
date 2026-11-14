<?php

declare(strict_types=1);

namespace App\Domain\Translate;

use App\Domain\Documents\Document;
use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The translation of a document's recognised text into one language (point 172.2): made in the browser
 * (modules/translate.js, Bergamot), kept one per target language – translating again replaces it. Found by the
 * documents search with "Auch im erkannten Text".
 *
 * @property string $id
 * @property string $org_id
 * @property string $document_id
 * @property string $from_lang ISO 639-1, e.g. "el"
 * @property string $to_lang ISO 639-1, e.g. "de"
 * @property string $text
 * @property Carbon $created_at
 */
final class DocumentTranslation extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $table = 'document_translations';

    protected $fillable = ['org_id', 'document_id', 'from_lang', 'to_lang', 'text'];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
