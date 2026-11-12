<?php

declare(strict_types=1);

namespace App\Domain\Notes;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection as SupportCollection;

/**
 * A column of the notes board (like a Trello list): a name and a place.
 * Columns are the same for everyone in the organisation (A37).
 *
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property int $sort place on the board
 * @property string|null $color background #rrggbb from the palette; null = none
 * @property string|null $note_color default colour of new notes in it; null = Note::DEFAULT_COLOR
 * @property string $note_sort order of its notes: manual (dragged), date (newest first) or title
 */
final class NoteColumn extends Model
{
    use BelongsToOrg;
    use HasUlids;

    public const SORTS = ['manual', 'date', 'title'];

    protected $fillable = ['org_id', 'name', 'sort', 'color', 'note_color', 'note_sort'];

    protected $attributes = ['note_sort' => 'manual'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'column_id');
    }

    /** Default colour of a new note in this column. */
    public function newNoteColor(): string
    {
        return $this->note_color ?? Note::DEFAULT_COLOR;
    }

    /**
     * Its notes in the column's order: dragged (they come in that order), newest first, or by title.
     *
     * @param  SupportCollection<int, Note>  $notes
     * @return SupportCollection<int, Note>
     */
    public function order(SupportCollection $notes): SupportCollection
    {
        return match ($this->note_sort) {
            'date' => $notes->sortByDesc(static fn (Note $note): string => $note->created_at->format('YmdHisu').$note->id)->values(),
            'title' => $notes->sortBy(static fn (Note $note): string => mb_strtolower(trim((string) ($note->title ?? $note->body))), SORT_NATURAL)->values(),
            default => $notes->values(),
        };
    }

    /**
     * The board's columns in order; an empty board gets its first column.
     *
     * @return Collection<int, NoteColumn>
     */
    public static function board(): Collection
    {
        $columns = self::query()->orderBy('sort')->orderBy('created_at')->get();
        if ($columns->isEmpty()) {
            $columns->push(self::query()->create(['name' => __('home.first_column'), 'sort' => 0]));
        }

        return $columns;
    }
}
