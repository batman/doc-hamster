<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Documents\Document;
use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A document linked to a bank transaction (n:m, A271). `state` suggested = the matcher linked it (A268), one click
 * confirms; confirmed = a person linked or confirmed it. `paid_at_set` is the paid_at date the confirmed link wrote
 * into the document (A272), so an unlink removes only that one.
 *
 * @property string $id
 * @property string $org_id
 * @property string $transaction_id
 * @property string $document_id
 * @property string|null $decided_by
 * @property string $state
 * @property int|null $score
 * @property array<string, int>|null $reasons
 * @property Carbon|null $paid_at_set
 * @property-read Transaction $transaction
 * @property-read Document $document
 */
final class TransactionMatch extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const SUGGESTED = 'suggested';

    public const CONFIRMED = 'confirmed';

    protected $table = 'matches';

    protected $fillable = ['org_id', 'transaction_id', 'document_id', 'decided_by', 'state', 'score', 'reasons', 'paid_at_set'];

    /** New rows are confirmed unless the matcher says otherwise (the column default, also for old rows). */
    protected $attributes = ['state' => self::CONFIRMED];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['score' => 'integer', 'reasons' => 'array', 'paid_at_set' => 'date'];
    }

    public function isSuggested(): bool
    {
        return $this->state === self::SUGGESTED;
    }

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
