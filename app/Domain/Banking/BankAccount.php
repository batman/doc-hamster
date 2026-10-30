<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property string $currency
 */
final class BankAccount extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'name', 'currency'];
}
