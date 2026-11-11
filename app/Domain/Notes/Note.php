<?php

declare(strict_types=1);

namespace App\Domain\Notes;

use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\Tag;
use App\Domain\Org\BelongsToOrg;
use App\Domain\Palette\PaletteColor;
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
 * @property list<array{text: string, done: bool}>|null $checklist old checklist; since 2026-11-07 lists live in the body ("[a, #b]")
 * @property bool $shared
 * @property bool $editable
 * @property string|null $title
 * @property string|null $color #rrggbb from the palette; null = DEFAULT_COLOR
 * @property int|null $opacity percent, 30-100; null = 100
 * @property bool $locked nobody changes, moves or deletes it until it is unlocked
 * @property int|null $pos_x place on the board in px; null = next free slot
 * @property int|null $pos_y
 * @property int|null $width size on the board in px; null = default
 * @property int|null $height
 * @property string|null $column_id board column; null = the first column
 * @property int $sort place in the column
 * @property Carbon|null $archived_at archived notes leave the board (A41)
 * @property Carbon $created_at
 * @property-read User $author
 */
final class Note extends Model
{
    use BelongsToOrg;
    use HasUlids;

    /** Colour of a note without one (yellow). Notes pick from the shared palette (points 14-15) and store the hex. */
    public const DEFAULT_COLOR = '#fdd835';

    protected $fillable = ['org_id', 'user_id', 'body', 'checklist', 'shared', 'editable', 'title', 'color', 'opacity', 'locked', 'pos_x', 'pos_y', 'width', 'height', 'column_id', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['checklist' => 'array', 'shared' => 'boolean', 'editable' => 'boolean', 'locked' => 'boolean', 'opacity' => 'integer', 'pos_x' => 'integer', 'pos_y' => 'integer', 'width' => 'integer', 'height' => 'integer', 'sort' => 'integer', 'archived_at' => 'datetime'];
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
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'note_tags')->orderBy('name');
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

    /** Framework colour class (c-rrggbb, rules from tags.css). */
    public function colorClass(): string
    {
        return PaletteColor::cssClass($this->color ?? self::DEFAULT_COLOR);
    }

    public function editableBy(string $userId): bool
    {
        return $this->user_id === $userId || ($this->shared && $this->editable);
    }
}
