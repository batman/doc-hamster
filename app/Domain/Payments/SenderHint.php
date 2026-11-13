<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What the app learnt about one sender (A236): its IBAN and where its amount stands (the label on the amount's
 * line, the place on the page), taken from values a person confirmed or corrected. InvoiceReader prefers them.
 *
 * @property string $id
 * @property string $org_id
 * @property string $sender_key normalised (InvoiceSuggestions::senderKey)
 * @property string|null $iban
 * @property string|null $amount_keyword
 * @property int|null $amount_page
 * @property array{x: float, y: float, w: float, h: float}|null $amount_box
 */
final class SenderHint extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'sender_key', 'iban', 'amount_keyword', 'amount_page', 'amount_box'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_page' => 'integer', 'amount_box' => 'array'];
    }
}
