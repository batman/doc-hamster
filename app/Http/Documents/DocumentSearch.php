<?php

declare(strict_types=1);

namespace App\Http\Documents;

use App\Domain\Documents\Document;
use App\Domain\Documents\ReceiptDate;
use App\Domain\Documents\Tag;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertySearch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * The search of a documents window (points 4-7, 102-106, 122.6, 135): the text, the advanced search panel's fields and
 * the property filters, read from the address (all of it stays in the address, point 7) and applied to a query.
 */
final class DocumentSearch
{
    /** The panel's own query fields (besides q and the property fields). */
    public const PARAMS = ['in[]', 'ocr', 'all', 'tags[]', 'any', 'by', 'from', 'to', 'match', 'read', 'type', 'nodate'];

    /** Where the text is looked for ("Suchen in", point 187.1), in the order of the chips. */
    public const IN = ['name', 'ocr', 'note', 'tags', 'props'];

    /** The chips that are on without an `in[]` in the address. */
    public const IN_DEFAULT = ['name', 'ocr'];

    /** The period buttons of the panel (point 135, A283), in their order. */
    public const PRESETS = ['this_month', 'last_month', 'this_year', 'last_year'];

    /**
     * @param  Collection<int, Tag>  $tags
     * @param  list<string>  $selected
     * @param  array{from?: string, to?: string}  $period
     * @param  Collection<int, Property>  $properties
     * @param  array<string, array<string, string>>  $propFilters
     * @param  list<string>  $in
     */
    private function __construct(
        public readonly string $q,
        public readonly array $in,
        public readonly bool $all,
        public readonly Collection $tags,
        public readonly array $selected,
        public readonly bool $anyTag,
        public readonly string $by,
        public readonly array $period,
        public readonly string $match,
        public readonly string $read,
        public readonly string $type,
        public readonly Collection $properties,
        public readonly array $propFilters,
        /** Point 214: only documents without a Belegdatum of their own ("ohne Belegdatum"). */
        public readonly bool $noDate = false,
    ) {}

    public static function read(Request $request): self
    {
        $tags = Tag::query()->orderBy('name')->get();
        $wanted = $request->query('tags');
        $period = [];
        foreach (['from', 'to'] as $end) {
            $value = $request->query($end);
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $period[$end] = $value;
            }
        }
        $pick = static fn (string $name, array $allowed): string => in_array($request->query($name), $allowed, true) ? (string) $request->query($name) : '';
        $properties = Property::ordered(true);

        return new self(
            q: trim((string) $request->query('q', '')),
            in: self::readIn($request),
            all: $request->query('all') === '1',
            tags: $tags,
            selected: array_values($tags->pluck('id')->intersect(is_array($wanted) ? $wanted : [])->values()->all()),
            anyTag: $request->query('any') === '1',
            by: $request->query('by') === 'upload' ? 'upload' : 'doc',
            period: $period,
            match: $pick('match', ['yes', 'no']),
            read: $pick('read', ['yes', 'no']),
            type: $pick('type', ['pdf', 'image']),
            properties: $properties,
            propFilters: PropertySearch::read($request, $properties),
            noDate: $request->query('nodate') === '1',
        );
    }

    /**
     * "Suchen in" (point 187.1): the chips from `in[]`, the default (name and recognised text) without any; the old
     * `ocr=1` still adds the recognised text.
     *
     * @return list<string>
     */
    private static function readIn(Request $request): array
    {
        $wanted = $request->query('in');
        $in = is_array($wanted) ? array_intersect(self::IN, $wanted) : [];
        if ($in === []) {
            $in = self::IN_DEFAULT;
        }
        if ($request->query('ocr') === '1') {
            $in[] = 'ocr';
        }

        return array_values(array_intersect(self::IN, $in));
    }

    /**
     * Narrows the documents: tags (all of them, or at least one), the text where "Suchen in" says (name, recognised
     * text, note, tag names, property values), the period by receipt or upload date, status, file type and the
     * property filters.
     *
     * @param  Builder<Document>  $query
     */
    public function apply(Builder $query): void
    {
        if ($this->selected !== [] && $this->anyTag) {
            $query->whereHas('tags', fn ($tag) => $tag->whereKey($this->selected));
        } else {
            foreach ($this->selected as $tagId) {
                $query->whereHas('tags', static fn ($tag) => $tag->whereKey($tagId));
            }
        }
        if ($this->q !== '') {
            $like = '%'.addcslashes($this->q, '%_\\').'%';
            $in = array_flip($this->in);
            $query->where(static function ($w) use ($in, $like): void {
                if (isset($in['name'])) {
                    $w->orWhere('title', 'like', $like);
                }
                if (isset($in['ocr'])) {
                    // 172.2: the stored translations of the recognised text count as recognised text.
                    $w->orWhere('ocr_text', 'like', $like)
                        ->orWhereHas('translations', static fn ($t) => $t->where('text', 'like', $like));
                }
                if (isset($in['note'])) {
                    $w->orWhere('note', 'like', $like);
                }
                if (isset($in['tags'])) {
                    $w->orWhereHas('tags', static fn ($tag) => $tag->where('name', 'like', $like));
                }
                if (isset($in['props'])) {
                    $w->orWhereHas('properties', static fn ($value) => $value->where('value_text', 'like', $like));
                }
            });
        }
        $periodSql = Document::dateSql($this->by);
        if (isset($this->period['from'])) {
            $query->whereRaw("$periodSql >= ?", [$this->period['from']]);
        }
        if (isset($this->period['to'])) {
            $query->whereRaw("$periodSql <= ?", [$this->period['to']]);
        }
        if ($this->match !== '') {
            $this->match === 'yes' ? $query->whereHas('matches') : $query->whereDoesntHave('matches');
        }
        if ($this->read !== '') {
            $this->read === 'yes' ? $query->where('ocr_status', 'done') : $query->where(static fn ($w) => $w->whereNull('ocr_status')->orWhere('ocr_status', '!=', 'done'));
        }
        if ($this->noDate) {
            $query->where(static fn ($w) => $w->whereNull('doc_date_source')->orWhere('doc_date_source', ReceiptDate::UPLOAD));
        }
        if ($this->type !== '') {
            $pdf = $this->type === 'pdf';
            $query->whereHas('files', static fn ($file) => $pdf ? $file->where('mime', 'application/pdf') : $file->where('mime', 'like', 'image/%'));
        }
        PropertySearch::apply($query, $this->propFilters, $this->properties);
    }

    /**
     * The active filters (for the count on the chevron, A3).
     *
     * @return array<string, true>
     */
    public function filters(): array
    {
        $filters = array_filter(['tags' => $this->selected !== [], 'from' => isset($this->period['from']), 'to' => isset($this->period['to']),
            'match' => $this->match !== '', 'read' => $this->read !== '', 'type' => $this->type !== '', 'nodate' => $this->noDate]);
        foreach (array_keys($this->propFilters) as $propertyId) {
            $filters['prop_'.$propertyId] = true;
        }

        return $filters;
    }

    /** A search shows a result list of documents only (point 105). */
    public function searching(): bool
    {
        return $this->q !== '' || $this->filters() !== [];
    }

    /**
     * The days of a period button (point 135): [from, to] as Y-m-d.
     *
     * @return array{string, string}
     */
    public static function presetRange(string $preset, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        [$lo, $hi] = match ($preset) {
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            'last_year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };

        return [$lo->toDateString(), $hi->toDateString()];
    }

    /** Which period button the set dates are: a preset, `custom` for other dates, null without a period. */
    public function preset(?CarbonImmutable $now = null): ?string
    {
        if ($this->period === []) {
            return null;
        }
        foreach (self::PRESETS as $preset) {
            if (self::presetRange($preset, $now) === [$this->period['from'] ?? '', $this->period['to'] ?? '']) {
                return $preset;
            }
        }

        return 'custom';
    }

    /**
     * The query fields of the whole panel, for `search-params` and the "close search" link.
     *
     * @return list<string>
     */
    public function params(): array
    {
        return [...self::PARAMS, ...PropertySearch::params($this->properties)];
    }

    /**
     * What the window view gets.
     *
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        return [
            'tags' => $this->tags,
            'selected' => $this->selected,
            'searching' => $this->searching(),
            'search' => $this,
            'in' => $this->in,
            'all' => $this->all,
            'anyTag' => $this->anyTag,
            'by' => $this->by,
            'period' => $this->period,
            'match' => $this->match,
            'read' => $this->read,
            'type' => $this->type,
            'noDate' => $this->noDate,
            'filters' => $this->filters(),
            'properties' => $this->properties,
            'propFilters' => $this->propFilters,
            'propParams' => PropertySearch::params($this->properties),
        ];
    }
}
