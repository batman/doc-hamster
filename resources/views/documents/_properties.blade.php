{{-- "Eigenschaften-Karte" of a document (points 122.4, A252): only properties with a value, each editable in place
     (click-to-edit; empty = removed); a suggested value carries the pill "vorgeschlagen" and a ✓ to confirm. A value with
     a stored place (page + box) emits document:highlight on click (properties.js). "+ Eigenschaft" opens the popup
     "Markieren als" (DocHamster.markAs). Only active properties the document may carry (A246). No ids inside: the card
     reloads itself (GET documents.properties) on properties:changed, in every window that shows this document. --}}
@php
    $propertyList = \App\Domain\Properties\Property::forDocument($document);
    $propertyValues = $document->properties()->get()->keyBy('property_id');
    $propertyShown = $propertyList->filter(fn ($p) => $propertyValues->has($p->id));
    $propertyFormat = app(\App\Domain\Properties\PropertyValues::class);
    $propertyEdit = Gate::allows('documents.edit');
@endphp
<section class="detail-section doc-properties" data-doc-properties="{{ route('documents.properties', $document->id) }}" data-document="{{ $document->id }}">
    <div class="doc-properties-head">
        <h3>{{ __('properties.title') }}</h3>
        @if ($propertyEdit && $propertyList->isNotEmpty())
            <button type="button" class="button-quiet button-small" data-mark-as="{{ $document->id }}" aria-label="{{ __('properties.card.add_label') }}"><x-icon name="plus" />{{ __('properties.card.add') }}</button>
        @endif
    </div>
    @if ($propertyShown->isEmpty())
        <p class="list-empty">{{ __('properties.card.none') }}</p>
    @else
        <ul class="prop-list">
            @foreach ($propertyShown as $property)
                @php $value = $propertyValues[$property->id]->setRelation('property', $property); $text = $propertyFormat->display($value); @endphp
                <li class="prop-row @if ($value->isSuggested()) is-suggested @endif" data-property="{{ $property->id }}"
                    @if ($value->page !== null && $value->box !== null) data-page="{{ $value->page }}" data-box="{{ json_encode($value->box) }}" @endif>
                    <span class="prop-name">{{ $property->name }}</span>
                    <span class="prop-value">
                        @if ($propertyEdit)
                            <x-edit-in-place :name="$property->id" :value="$text" :action="route('documents.properties.set', $document->id)" :label="$property->name" maxlength="500" />
                        @else
                            <span>{{ $text }}</span>
                        @endif
                        @if ($value->isSuggested())
                            <span class="pill pill-new">{{ __('properties.card.suggested') }}</span>
                        @endif
                    </span>
                    @if ($propertyEdit)
                        @if ($value->isSuggested())
                            <form method="post" action="{{ route('documents.properties.confirm', [$document->id, $property->id]) }}" data-prop-action>
                                @csrf
                                <button type="submit" class="control-button" aria-label="{{ __('properties.card.confirm') }}: {{ $property->name }}" data-tooltip="{{ __('properties.card.confirm') }}"><x-icon name="check" /></button>
                            </form>
                        @endif
                        <span class="row-actions">
                            <form method="post" action="{{ route('documents.properties.delete', [$document->id, $property->id]) }}" data-prop-action>
                                @csrf
                                <button type="submit" class="control-button" aria-label="{{ __('properties.card.remove') }}: {{ $property->name }}" data-tooltip="{{ __('properties.card.remove') }}"><x-icon name="x" /></button>
                            </form>
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    @if ($propertyEdit && $propertyList->isNotEmpty())
        <noscript>
            <form method="post" action="{{ route('documents.properties.set', $document->id) }}" class="search-row">
                @csrf
                <select class="input-small" name="property" aria-label="{{ __('properties.mark.property') }}">
                    @foreach ($propertyList as $property)
                        <option value="{{ $property->id }}">{{ $property->name }}</option>
                    @endforeach
                </select>
                <input class="input-small" name="value" aria-label="{{ __('properties.card.value') }}">
                <button type="submit" class="button-small">{{ __('properties.save') }}</button>
            </form>
        </noscript>
    @endif
</section>
