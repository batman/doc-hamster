<?php

declare(strict_types=1);

namespace App\Domain\Properties;

use App\Domain\Documents\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * The property filters of the advanced search (point 122.6) and the property columns of the details view (A244).
 * One set of query fields per property, flat so the window search can own them: `p_<id>_from` / `p_<id>_to`
 * (amount, number, date), `p_<id>_q` (text and IBAN contain, choice equals) and `p_<id>_has` (yes = has a value,
 * no = missing).
 */
final class PropertySearch
{
    /**
     * The query fields of these properties (for `search-params` and the "close search" link).
     *
     * @param  iterable<Property>  $properties
     * @return list<string>
     */
    public static function params(iterable $properties): array
    {
        $names = [];
        foreach ($properties as $property) {
            foreach (self::fieldsOf($property) as $field) {
                $names[] = self::name($property, $field);
            }
        }

        return $names;
    }

    public static function name(Property $property, string $field): string
    {
        return 'p_'.$property->id.'_'.$field;
    }

    /**
     * The fields one property offers: a range for amounts, numbers and dates, a text otherwise, always "has".
     *
     * @return list<string>
     */
    public static function fieldsOf(Property $property): array
    {
        return in_array($property->type, ['amount', 'number', 'date'], true) ? ['from', 'to', 'has'] : ['q', 'has'];
    }

    /**
     * The filters set in the request, checked: property id => [field => raw value]. Values that make no sense
     * (a date that is none, an amount that can't be read) are dropped.
     *
     * @param  Collection<int, Property>  $properties
     * @return array<string, array<string, string>>
     */
    public static function read(Request $request, Collection $properties): array
    {
        $out = [];
        foreach ($properties as $property) {
            foreach (self::fieldsOf($property) as $field) {
                $value = $request->query(self::name($property, $field));
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }
                $value = trim($value);
                $ok = match (true) {
                    $field === 'has' => in_array($value, ['yes', 'no'], true),
                    $property->type === 'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
                    $property->type === 'amount' => PropertyValues::parseAmount($value) !== null,
                    $property->type === 'number' => PropertyValues::parseNumber($value) !== null,
                    default => mb_strlen($value) <= 200,
                };
                if ($ok) {
                    $out[$property->id][$field] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * Narrows the documents by the filters.
     *
     * @param  Builder<Document>  $query
     * @param  array<string, array<string, string>>  $filters
     * @param  Collection<int, Property>  $properties
     */
    public static function apply(Builder $query, array $filters, Collection $properties): void
    {
        foreach ($filters as $id => $fields) {
            $property = $properties->firstWhere('id', $id);
            if (! $property instanceof Property) {
                continue;
            }
            if (($fields['has'] ?? null) === 'no') {
                $query->whereDoesntHave('properties', static fn (Builder $value) => $value->where('property_id', $id));

                continue;
            }
            $conditions = array_diff_key($fields, ['has' => true]);
            if ($conditions === [] && ($fields['has'] ?? null) !== 'yes') {
                continue;
            }
            $query->whereHas('properties', static function (Builder $value) use ($id, $property, $conditions): void {
                $value->where('property_id', $id);
                foreach ($conditions as $field => $raw) {
                    match ($property->type) {
                        'date' => $value->whereDate('value_date', $field === 'from' ? '>=' : '<=', $raw),
                        'amount' => $value->where('value_int', $field === 'from' ? '>=' : '<=', PropertyValues::parseAmount($raw)[0] ?? 0),
                        'number' => $value->where('value_int', $field === 'from' ? '>=' : '<=', PropertyValues::parseNumber($raw) ?? 0),
                        'choice' => $value->where('value_text', $raw),
                        'iban' => $value->where('value_text', 'like', '%'.addcslashes(strtoupper((string) preg_replace('/\s+/', '', $raw)), '%_\\').'%'),
                        default => $value->where('value_text', 'like', '%'.addcslashes($raw, '%_\\').'%'),
                    };
                }
            });
        }
    }

    /**
     * One chip per set field (point 6): [query field, label].
     *
     * @param  array<string, array<string, string>>  $filters
     * @param  Collection<int, Property>  $properties
     * @return list<array{string, string}>
     */
    public static function chips(array $filters, Collection $properties): array
    {
        $chips = [];
        foreach ($filters as $id => $fields) {
            $property = $properties->firstWhere('id', $id);
            if (! $property instanceof Property) {
                continue;
            }
            foreach ($fields as $field => $raw) {
                $text = match (true) {
                    $field === 'has' => __($raw === 'yes' ? 'properties.search.has' : 'properties.search.missing'),
                    $property->type === 'date' => __('properties.search.'.$field).' '.date('d.m.Y', (int) strtotime($raw)),
                    in_array($field, ['from', 'to'], true) => __('properties.search.'.$field).' '.$raw,
                    $property->type === 'choice' => '= '.$raw,
                    default => __('properties.search.contains').' "'.$raw.'"',
                };
                $chips[] = [self::name($property, $field), $property->name.': '.$text];
            }
        }

        return $chips;
    }

    /**
     * Orders by a property's value (A244, ?sort=prop:<id>): the column of its type; documents without a value last.
     *
     * @param  Builder<Document>  $query
     */
    public static function orderBy(Builder $query, Property $property, string $direction): void
    {
        $column = match ($property->type) {
            'amount', 'number' => 'value_int',
            'date' => 'value_date',
            default => 'value_text',
        };
        $value = static fn () => DocumentProperty::query()->whereColumn('document_properties.document_id', 'documents.id')->where('property_id', $property->id)->limit(1);
        // Documents with a value first (1 before NULL), then by the value.
        $query->orderByDesc($value()->selectRaw('1'))->orderBy($value()->select($column), $direction === 'desc' ? 'desc' : 'asc');
    }
}
