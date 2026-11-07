<?php

declare(strict_types=1);

namespace App\Domain\Notes;

use App\Domain\Org\BelongsToOrg;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A column of the notes board (like a Trello list): a name and a place.
 * Columns are the same for everyone in the organisation (A37).
 *
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property int $sort
 */
final class NoteColumn extends Model
{
    use BelongsToOrg;
    use HasUlids;

    protected $fillable = ['org_id', 'name', 'sort'];

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
