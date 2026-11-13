<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A pair a person rejected (A268): the matcher never suggests it again.
 *
 * @property string $id
 * @property string $org_id
 * @property string $transaction_id
 * @property string $document_id
 */
final class MatchRejection extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'transaction_id', 'document_id'];
}
