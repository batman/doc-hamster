<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Org\BelongsToOrg;
use App\Domain\Org\OrgScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An assistant's proposal (D-040, "Empfehlungskachel"): it changes nothing until a person takes it over. Kinds and
 * their payload: link {transaction_id, document_id}, unlink {transaction_id, document_id} (A52), move {document_id,
 * folder_id}, duplicate {keep_id, remove_id} (A56: links, confirmed values, note and folder go to the kept one),
 * delete {document_id} (Papierkorb only), shrink {document_id}, straighten {document_id, angle, cx, cy, w, h}
 * (Straightener), correct {document_id, property, value} (value "" clears it, A52), title {document_id, title} (A52),
 * setting {setting}. The same open proposal exists once (fingerprint). A51: sure = the assistant is sure (null: it
 * did not say); docHamster's own proposals (A49, A50) carry no assistant name.
 *
 * @property string $id
 * @property string $org_id
 * @property string $kind
 * @property array<string, string> $payload
 * @property string|null $reason
 * @property bool|null $sure
 * @property string $fingerprint
 * @property string $status open|accepted|rejected|failed|withdrawn
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

    public const UNLINK = 'unlink';

    public const TITLE = 'title';

    public const MOVE = 'move';

    public const DUPLICATE = 'duplicate';

    public const DELETE = 'delete';

    public const SHRINK = 'shrink';

    public const STRAIGHTEN = 'straighten';

    public const CORRECT = 'correct';

    public const SETTING = 'setting';

    /** In this order on the page. */
    public const KINDS = [self::LINK, self::UNLINK, self::MOVE, self::DUPLICATE, self::DELETE, self::STRAIGHTEN, self::SHRINK, self::CORRECT, self::TITLE, self::SETTING];

    /** Which right of the door each kind needs. */
    public const RIGHT = [
        self::LINK => AssistantAccess::PROPOSE_LINK, self::UNLINK => AssistantAccess::PROPOSE_LINK, self::MOVE => AssistantAccess::PROPOSE_MOVE, self::TITLE => AssistantAccess::PROPOSE_CORRECT,
        self::DUPLICATE => AssistantAccess::PROPOSE_CLEANUP, self::DELETE => AssistantAccess::PROPOSE_CLEANUP,
        self::SHRINK => AssistantAccess::PROPOSE_SHRINK, self::STRAIGHTEN => AssistantAccess::PROPOSE_STRAIGHTEN, self::CORRECT => AssistantAccess::PROPOSE_CORRECT,
        self::SETTING => AssistantAccess::PROPOSE_SETTINGS,
    ];

    /** The settings an assistant may propose to switch on. */
    public const SETTINGS = ['optimize_on'];

    public const OPEN = 'open';

    public const WITHDRAWN = 'withdrawn';

    protected $table = 'assistant_proposals';

    protected $fillable = ['org_id', 'kind', 'payload', 'reason', 'sure', 'fingerprint', 'status', 'result', 'assistant', 'decided_by', 'decided_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'sure' => 'boolean', 'decided_at' => 'datetime'];
    }

    /**
     * Files a proposal unless the same one is open already.
     *
     * @param  array<string, string>  $payload
     * @return array{self, bool} the proposal and whether it is new
     */
    public static function file(string $kind, array $payload, ?string $reason, ?string $assistant, ?bool $sure = null, ?string $orgId = null): array
    {
        ksort($payload);
        $fingerprint = hash('sha256', $kind.'|'.json_encode($payload));
        // A job (A49, A50) names its organisation; a request has the active one.
        $query = static fn () => $orgId === null ? self::query() : self::query()->withoutGlobalScope(OrgScope::class)->where('org_id', $orgId);
        $open = $query()->where('fingerprint', $fingerprint)->where('status', self::OPEN)->first();
        if ($open !== null) {
            return [$open, false];
        }

        return [$query()->create([
            'org_id' => $orgId,
            'kind' => $kind, 'payload' => $payload, 'fingerprint' => $fingerprint, 'status' => self::OPEN,
            'reason' => $reason === null ? null : mb_substr(trim($reason), 0, 2000), 'assistant' => $assistant, 'sure' => $sure,
        ]), true];
    }

    /**
     * A straighten proposal's numbers.
     *
     * @return array{float, array{cx: float, cy: float, w: float, h: float}|null}
     */
    public function straightening(): array
    {
        $p = $this->payload;
        $num = static fn (string $key): ?float => is_numeric($p[$key] ?? null) ? (float) $p[$key] : null;
        $box = $num('cx') !== null && $num('cy') !== null && $num('w') !== null && $num('h') !== null
            ? ['cx' => (float) $num('cx'), 'cy' => (float) $num('cy'), 'w' => (float) $num('w'), 'h' => (float) $num('h')] : null;

        return [(float) ($num('angle') ?? 0.0), $box];
    }
}
