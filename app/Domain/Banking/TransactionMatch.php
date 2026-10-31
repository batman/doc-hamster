<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Documents\Document;
use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A receipt linked to a bank transaction, by hand for now.
 *
 * @property string $id
 * @property string $org_id
 * @property string $transaction_id
 * @property string $document_id
 * @property string|null $decided_by
 * @property-read Transaction $transaction
 */
final class TransactionMatch extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $table = 'matches';

    protected $fillable = ['org_id', 'transaction_id', 'document_id', 'decided_by'];

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
