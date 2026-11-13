<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Documents\Tag;
use App\Domain\Properties\Property;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Einstellungen → Eigenschaften (point 122.2): the organisation's document properties – add, rename, sort, switch
 * on and off, limit to tags (A246), delete. Default properties (with a key) can be switched off but not deleted.
 */
final class PropertyController
{
    public function index(): View
    {
        return view('settings.properties', ['properties' => Property::ordered(), 'tags' => Tag::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(Property::TYPES)],
            'options' => ['nullable', 'string', 'max:5000'],
            ...$this->tagRules(),
        ]);
        $options = $this->options($data['options'] ?? null);
        if ($data['type'] === 'choice' && $options === []) {
            throw ValidationException::withMessages(['options' => __('properties.errors.options')]);
        }

        Property::query()->create([
            'name' => trim($data['name']), 'type' => $data['type'], 'options' => $data['type'] === 'choice' ? $options : null,
            'tag_ids' => $this->tagIds($data), 'sort' => (int) Property::query()->max('sort') + 1, 'active' => true,
        ]);

        return redirect()->route('properties.index')->with('status', __('properties.created'));
    }

    /** Autosave of the edit dialog and the on/off switch of a row: only the fields the form sends change. */
    public function update(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $property = Property::query()->whereKey($id)->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'active' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ...$this->tagRules(),
        ]);

        $changes = [];
        if (isset($data['name'])) {
            $changes['name'] = trim($data['name']);
        }
        if (array_key_exists('active', $data)) {
            $changes['active'] = (bool) $data['active'];
        }
        if ($property->type === 'choice' && $request->has('options')) {
            $options = $this->options($data['options'] ?? null);
            if ($options === []) {
                throw ValidationException::withMessages(['options' => __('properties.errors.options')]);
            }
            $changes['options'] = $options;
        }
        if ($request->boolean('tags_present')) {
            $changes['tag_ids'] = $this->tagIds($data);
        }
        $property->update($changes);

        if (! $request->wantsJson()) {
            return redirect()->route('properties.index')->with('status', __('properties.saved'));
        }

        return response()->json(['message' => __('properties.saved'), 'property' => [
            'id' => $property->id, 'name' => $property->name, 'active' => $property->active,
            'scope' => $this->scopeLabel($property, Tag::query()->get()),
        ]]);
    }

    public function destroy(string $id): RedirectResponse
    {
        $property = Property::query()->whereKey($id)->firstOrFail();
        if ($property->isDefault()) {
            return redirect()->route('properties.index')->withErrors(['property' => __('properties.default_locked')]);
        }
        $property->delete();

        return redirect()->route('properties.index')->with('status', __('properties.deleted'));
    }

    /** New order from the sortable list (framework, list:sorted). */
    public function order(Request $request): JsonResponse
    {
        $ids = Property::query()->pluck('id')->all();
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['string', Rule::in($ids)]]);

        foreach (array_values(array_unique($data['order'])) as $sort => $id) {
            Property::query()->whereKey($id)->update(['sort' => $sort]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * "alle Dokumente" or "nur #Rechnung, #Quittung" (row subtitle, A246).
     *
     * @param  Collection<int, Tag>  $tags
     */
    public static function scopeLabel(Property $property, Collection $tags): string
    {
        $names = $tags->whereIn('id', $property->tag_ids ?? [])->map(static fn (Tag $tag): string => '#'.$tag->name);

        return $names->isEmpty() ? __('properties.all_documents') : __('properties.only_tags', ['tags' => $names->implode(', ')]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function tagRules(): array
    {
        return ['tags' => ['sometimes', 'array'], 'tags.*' => [Rule::in(Tag::query()->pluck('id')->all())]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>|null
     */
    private function tagIds(array $data): ?array
    {
        $ids = is_array($data['tags'] ?? null) ? array_values(array_unique(array_map('strval', $data['tags']))) : [];

        return $ids === [] ? null : $ids;
    }

    /**
     * @return list<string>
     */
    private function options(?string $text): array
    {
        $lines = array_map(static fn (string $line): string => mb_substr(trim($line), 0, 100), preg_split('/\R/', (string) $text) ?: []);

        return array_values(array_unique(array_filter($lines, static fn (string $line): bool => $line !== '')));
    }
}
