<?php

declare(strict_types=1);

namespace App\Http\Documents;

use App\Domain\Documents\Document;
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
    public const PARAMS = ['ocr', 'all', 'tags[]', 'any', 'by', 'from', 'to', 'match', 'read', 'type'];

    /** The period buttons of the panel (point 135, A283), in their order. */
    public const PRESETS = ['this_month', 'last_month', 'this_year', 'last_year'];

    /**
     * @param  Collection<int, Tag>  $tags
     * @param  list<string>  $selected
     * @param  array{from?: string, to?: string}  $period
     * @param  Collection<int, Property>  $properties
     * @param  array<string, array<string, string>>  $propFilters
     */
    private function __construct(
        public readonly string $q,
        public readonly bool $ocr,
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
            ocr: $request->query('ocr') === '1',
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
        );
    }

    /**
     * Narrows the documents: tags (all of them, or at least one), the text in title, note and tag names (with ocr also
     * in the recognised text), the period by receipt or upload date, status, file type and the property filters.
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
            $ocr = $this->ocr;
            $query->where(static fn ($w) => $w->where('title', 'like', $like)
                ->orWhere('note', 'like', $like)
                ->orWhereHas('tags', static fn ($tag) => $tag->where('name', 'like', $like))
                ->when($ocr, static fn ($w) => $w->orWhere('ocr_text', 'like', $like)));
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
            'match' => $this->match !== '', 'read' => $this->read !== '', 'type' => $this->type !== '']);
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
            'ocr' => $this->ocr,
            'all' => $this->all,
            'anyTag' => $this->anyTag,
            'by' => $this->by,
            'period' => $this->period,
            'match' => $this->match,
            'read' => $this->read,
            'type' => $this->type,
            'filters' => $this->filters(),
            'properties' => $this->properties,
            'propFilters' => $this->propFilters,
            'propParams' => PropertySearch::params($this->properties),
        ];
    }
}
