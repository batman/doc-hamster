<?php

declare(strict_types=1);

namespace App\Domain\Ocr;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A243: one OCR correction learned for one sender – how often "original" was corrected to "corrected" on documents
 * of that sender. Offered (never applied silently) on the sender's other documents.
 *
 * @property string $id
 * @property string $org_id
 * @property string $sender_key
 * @property string $original
 * @property string $corrected
 * @property int $count
 */
final class OcrLearned extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $table = 'ocr_learned';

    protected $fillable = ['org_id', 'sender_key', 'original', 'corrected', 'count'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['count' => 'integer'];
    }
}
