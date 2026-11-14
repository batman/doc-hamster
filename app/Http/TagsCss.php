<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Documents\Folder;
use App\Domain\Documents\Tag;
use App\Domain\Notes\Note;
use App\Domain\Notes\NoteColumn;
use App\Domain\Org\Organization;
use App\Domain\Palette\PaletteColor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Point 164.4: tags.css carries a version in its address (?v=…), so the browser keeps it instead of asking on
 * every page. The version changes whenever something it is made of changes: a tag, a palette colour, a note,
 * folder or note column colour, the Papierkorb colour.
 */
final class TagsCss
{
    public const string KEY = 'tags.css.version';

    /** The models whose colours go into tags.css, with the attributes that matter (null: any change). */
    private const array SOURCES = [
        Tag::class => null,
        PaletteColor::class => null,
        Note::class => ['color'],
        Folder::class => ['color'],
        NoteColumn::class => ['color', 'note_color'],
        Organization::class => ['settings_json'],
    ];

    public static function version(): string
    {
        $version = Cache::memo()->get(self::KEY);
        if (is_string($version) && $version !== '') {
            return $version;
        }

        return self::bump();
    }

    public static function bump(): string
    {
        $version = Str::lower(Str::random(8));
        Cache::memo()->forever(self::KEY, $version);

        return $version;
    }

    /** Called once from AppServiceProvider::boot(). */
    public static function watch(): void
    {
        foreach (self::SOURCES as $model => $attributes) {
            $model::saved(static function (Model $saved) use ($attributes): void {
                if ($attributes === null || $saved->wasRecentlyCreated || $saved->wasChanged($attributes)) {
                    self::bump();
                }
            });
            $model::deleted(static fn (): string => self::bump());
        }
    }
}
