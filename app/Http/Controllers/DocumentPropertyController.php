<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Domain\Properties\DocumentProperty;
use App\Domain\Properties\Property;
use App\Domain\Properties\PropertyValues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The property values of one document (points 122.1, 122.4): the card in the detail window, the popup
 * "Markieren als" (DocHamster.markAs) and click-to-edit. Answers JSON; without JavaScript the forms go back.
 */
final class DocumentPropertyController
{
    public function __construct(private readonly PropertyValues $values) {}

    /** The card again (it reloads itself on properties:changed) and the properties the popup offers. */
    public function card(Request $request, string $id): JsonResponse
    {
        $document = $this->document($request, $id);

        return response()->json([
            'html' => view('documents._properties', ['document' => $document])->render(),
            'properties' => $this->offered($document),
        ]);
    }

    /** Sets a confirmed value; `property` is an id or a default key (click-to-edit sends it as `field`). An empty value removes it. */
    public function store(Request $request, string $id, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $document = $this->document($request, $id);
        $data = $this->input($request);
        $property = $this->allowed($document, $data['property']);

        if (trim($data['value']) === '') {
            $document->properties()->where('property_id', $property->id)->delete();
            $audit->record('document.changed', orgId: $org->id(), userId: $this->user($request)->id, subject: $document, ip: $request->ip());

            return $this->answer($request, __('properties.card.removed'), ['value' => '', 'property' => $property->id]);
        }

        $value = $this->values->set($document, $property, $data['value'], DocumentProperty::CONFIRMED, $data['page'], $data['box'], $data['order']);
        $audit->record('document.changed', orgId: $org->id(), userId: $this->user($request)->id, subject: $document, ip: $request->ip());

        return $this->answer($request, __('properties.saved'), ['value' => $this->values->display($value), 'property' => $property->id]);
    }

    /**
     * The live preview of the popup: how the value would be stored, or why not (422). A date that reads two ways
     * (165.6) also answers the order used (`order`, the sender's learned one by default, A343) and every reading
     * (`orders`: order, label "Tag, Monat, Jahr", text "3. April 2021") for the link that switches between them.
     */
    public function preview(Request $request, string $id): JsonResponse
    {
        $document = $this->document($request, $id);
        $data = $this->input($request);
        $property = $this->allowed($document, $data['property']);
        $order = $property->type === 'date' ? ($data['order'] ?? $this->values->senderOrder($document)) : null;

        $parsed = $this->values->parse($property, $data['value'], $order);
        $value = new DocumentProperty($parsed);
        $value->setRelation('property', $property);
        $current = $document->properties()->with('property')->where('property_id', $property->id)->first();
        $orders = [];
        if ($parsed['date_order'] !== null) {
            foreach (PropertyValues::dateReadings($data['value']) as $key => $date) {
                $orders[] = ['order' => $key, 'label' => __('properties.orders.'.$key), 'text' => PropertyValues::formatDateLong($date)];
            }
        }

        return response()->json([
            'value' => $this->values->display($value),
            'current' => $current !== null ? $this->values->display($current) : null,
            'order' => $parsed['date_order'],
            'orders' => $orders,
        ]);
    }

    public function confirm(Request $request, string $id, string $property): JsonResponse|RedirectResponse
    {
        $document = $this->document($request, $id);
        $value = $document->properties()->where('property_id', $property)->firstOrFail();
        $this->values->confirm($value);

        return $this->answer($request, __('properties.card.confirmed'), ['property' => $property]);
    }

    public function destroy(Request $request, string $id, string $property, AuditLog $audit, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $document = $this->document($request, $id);
        $document->properties()->where('property_id', $property)->firstOrFail()->delete();
        $audit->record('document.changed', orgId: $org->id(), userId: $this->user($request)->id, subject: $document, ip: $request->ip());

        return $this->answer($request, __('properties.card.removed'), ['property' => $property]);
    }

    /**
     * @return list<array{id: string, key: ?string, name: string, type: string, icon: string, options: list<string>, value: ?string}>
     */
    private function offered(Document $document): array
    {
        $current = $document->properties()->with('property')->get()->keyBy('property_id');
        $out = [];
        foreach (Property::forDocument($document) as $property) {
            $value = $current->get($property->id);
            $out[] = [
                'id' => $property->id, 'key' => $property->key, 'name' => $property->name, 'type' => $property->type,
                'icon' => $property->icon(), 'options' => $property->options ?? [],
                'value' => $value instanceof DocumentProperty ? $this->values->display($value) : null,
            ];
        }

        return $out;
    }

    /**
     * @return array{property: string, value: string, page: ?int, box: ?array<string, mixed>, order: ?string}
     */
    private function input(Request $request): array
    {
        if (! $request->filled('property') && $request->filled('field')) {
            $request->merge(['property' => $request->input('field')]);
        }
        $data = $request->validate([
            'property' => ['required', 'string', 'max:40'],
            'value' => ['nullable', 'string', 'max:1000'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'box' => ['nullable', 'array'],
            'box.*' => ['numeric'],
            'order' => ['nullable', 'string', 'in:'.implode(',', PropertyValues::ORDERS)],
        ]);

        return [
            'property' => (string) $data['property'],
            'value' => (string) ($data['value'] ?? ''),
            'page' => isset($data['page']) ? (int) $data['page'] : null,
            'box' => is_array($data['box'] ?? null) ? $data['box'] : null,
            'order' => isset($data['order']) ? (string) $data['order'] : null,
        ];
    }

    /** Only an active property the document may carry (A246). */
    private function allowed(Document $document, string $idOrKey): Property
    {
        $property = Property::forDocument($document)->first(static fn (Property $p): bool => $p->id === $idOrKey || $p->key === $idOrKey);
        if ($property === null) {
            throw ValidationException::withMessages(['property' => __('properties.errors.not_allowed')]);
        }

        return $property;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function answer(Request $request, string $message, array $extra = []): JsonResponse|RedirectResponse
    {
        return $request->wantsJson() ? response()->json(['message' => $message, ...$extra]) : back()->with('status', $message);
    }

    private function document(Request $request, string $id): Document
    {
        return Document::query()->visibleTo($this->user($request)->id, Gate::allows(Permission::ViewAllDocuments->value))
            ->with('tags')->whereKey($id)->firstOrFail();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
