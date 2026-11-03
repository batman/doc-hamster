<?php

declare(strict_types=1);

namespace App\Domain\Notes;

use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A sticky note on the home page. Private by default; with "Notizen für alle
 * zeigen" its author may show it to everyone and let others change it.
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string|null $body
 * @property list<array{text: string, done: bool}>|null $checklist
 * @property bool $shared
 * @property bool $editable
 * @property Carbon $created_at
 * @property-read User $author
 */
final class Note extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'user_id', 'body', 'checklist', 'shared', 'editable'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['checklist' => 'array', 'shared' => 'boolean', 'editable' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsToMany<Document, $this>
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'note_documents')->orderBy('title');
    }

    /**
     * Own notes and the ones shown to everyone.
     *
     * @param  Builder<Note>  $query
     * @return Builder<Note>
     */
    public function scopeVisibleTo(Builder $query, string $userId): Builder
    {
        return $query->where(static fn (Builder $q) => $q->where('user_id', $userId)->orWhere('shared', true));
    }

    public function editableBy(string $userId): bool
    {
        return $this->user_id === $userId || ($this->shared && $this->editable);
    }
}
