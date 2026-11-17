<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An assistant's proposal (D-040, "Empfehlungskachel"): it changes nothing until a person takes it over. Kinds and
 * their payload: link {transaction_id, document_id}, move {document_id, folder_id}, duplicate {keep_id, remove_id},
 * delete {document_id} (Papierkorb only), shrink {document_id}, correct {document_id, property, value},
 * setting {setting}. The same open proposal exists once (fingerprint).
 *
 * @property string $id
 * @property string $org_id
 * @property string $kind
 * @property array<string, string> $payload
 * @property string|null $reason
 * @property string $fingerprint
 * @property string $status open|accepted|rejected|failed
 * @property string|null $result what happened when it was taken over (or why not)
 * @property string|null $assistant the connection's name ("Claude")
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
final class Proposal extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const LINK = 'link';

    public const MOVE = 'move';

    public const DUPLICATE = 'duplicate';

    public const DELETE = 'delete';

    public const SHRINK = 'shrink';

    public const CORRECT = 'correct';

    public const SETTING = 'setting';

    /** In this order on the page. */
    public const KINDS = [self::LINK, self::MOVE, self::DUPLICATE, self::DELETE, self::SHRINK, self::CORRECT, self::SETTING];

    /** Which right of the door each kind needs. */
    public const RIGHT = [
        self::LINK => AssistantAccess::PROPOSE_LINK, self::MOVE => AssistantAccess::PROPOSE_MOVE,
        self::DUPLICATE => AssistantAccess::PROPOSE_CLEANUP, self::DELETE => AssistantAccess::PROPOSE_CLEANUP,
        self::SHRINK => AssistantAccess::PROPOSE_SHRINK, self::CORRECT => AssistantAccess::PROPOSE_CORRECT,
        self::SETTING => AssistantAccess::PROPOSE_SETTINGS,
    ];

    /** The settings an assistant may propose to switch on. */
    public const SETTINGS = ['optimize_on'];

    public const OPEN = 'open';

    protected $table = 'assistant_proposals';

    protected $fillable = ['org_id', 'kind', 'payload', 'reason', 'fingerprint', 'status', 'result', 'assistant', 'decided_by', 'decided_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime'];
    }

    /**
     * Files a proposal unless the same one is open already.
     *
     * @param  array<string, string>  $payload
     * @return array{self, bool} the proposal and whether it is new
     */
    public static function file(string $kind, array $payload, ?string $reason, ?string $assistant): array
    {
        ksort($payload);
        $fingerprint = hash('sha256', $kind.'|'.json_encode($payload));
        $open = self::query()->where('fingerprint', $fingerprint)->where('status', self::OPEN)->first();
        if ($open !== null) {
            return [$open, false];
        }

        return [self::query()->create([
            'kind' => $kind, 'payload' => $payload, 'fingerprint' => $fingerprint, 'status' => self::OPEN,
            'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 2000), 'assistant' => $assistant,
        ]), true];
    }
}
