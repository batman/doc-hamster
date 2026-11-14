{{-- Einstellungen → Eigenschaften (point 122.2): the document properties as a sortable list. Each row switches on/off
     (autosave) and opens its edit dialog (name, choice values, tag limit A246 – all autosave); only own properties delete. --}}
@extends('layouts.settings')

@section('title', __('properties.title'))

@section('page')
    <p class="muted">{{ __('properties.intro') }}</p>
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    @if ($properties->isEmpty())
        <p class="list-empty">{{ __('properties.empty') }}</p>
    @else
        <ul class="pick-list property-rows" data-sortable data-properties-order="{{ route('properties.order') }}" data-order-failed="{{ __('properties.order_invalid') }}">
            @foreach ($properties as $property)
                <li class="pick-row is-static property-row @unless ($property->active) is-off @endunless" data-id="{{ $property->id }}" data-property-row="{{ $property->id }}">
                    <button type="button" class="pick-handle" aria-label="{{ __('properties.drag', ['name' => $property->name]) }}"><x-icon name="grip-vertical" /></button>
                    <span class="row-icon"><x-icon :name="$property->icon()" /></span>
                    <span><strong data-property-name>{{ $property->name }}</strong><small>{{ __('properties.types.'.$property->type) }} · <span data-property-scope>{{ \App\Http\Controllers\PropertyController::scopeLabel($property, $tags) }}</span></small></span>
                    @if ($property->isDefault())
                        <span class="pill">{{ __('properties.default') }}</span>
                    @endif
                    <form method="post" action="{{ route('properties.update', $property->id) }}" data-autosave class="property-switch">
                        @csrf
                        <input type="hidden" name="active" value="0">
                        <label class="check" data-tooltip="{{ __('properties.active') }}"><input type="checkbox" name="active" value="1" @checked($property->active) aria-label="{{ __('properties.active') }}: {{ $property->name }}"></label>
                        <noscript><button type="submit">{{ __('properties.save') }}</button></noscript>
                    </form>
                    <span class="row-actions">
                        <button type="button" class="control-button" data-dialog-open="property-{{ $property->id }}" aria-label="{{ __('properties.edit') }}: {{ $property->name }}" data-tooltip="{{ __('properties.edit') }}"><x-icon name="settings" /></button>
                        @unless ($property->isDefault())
                            <button type="button" class="control-button" data-dialog-open="delete-property-{{ $property->id }}" aria-label="{{ __('properties.delete') }}: {{ $property->name }}" data-tooltip="{{ __('properties.delete') }}"><x-icon name="trash" /></button>
                        @endunless
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    @foreach ($properties as $property)
        <div class="dialog-backdrop" data-dialog="property-{{ $property->id }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="property-{{ $property->id }}-title">
                <h2 id="property-{{ $property->id }}-title">{{ __('properties.edit_title') }}</h2>
                {{-- Properties module (A191-A194): no save button, every change saves itself. --}}
                <form method="post" action="{{ route('properties.update', $property->id) }}" data-autosave>
                    @csrf
                    <div class="field">
                        <label for="property-name-{{ $property->id }}">{{ __('properties.name') }}</label>
                        <div class="field-addon">
                            <input id="property-name-{{ $property->id }}" name="name" value="{{ $property->name }}" maxlength="60" required>
                            <span class="addon-end"><button type="button" class="control-button addon-button" data-field-save aria-label="{{ __('properties.save') }}" data-tooltip="{{ __('properties.save') }}" hidden><x-icon name="check" /></button></span>
                        </div>
                        <p class="field-hint">{{ __('properties.types.'.$property->type) }}</p>
                    </div>
                    @if ($property->type === 'choice')
                        <div class="field">
                            <label for="property-options-{{ $property->id }}">{{ __('properties.options') }}</label>
                            <div class="field-addon">
                                <textarea id="property-options-{{ $property->id }}" name="options" rows="4">{{ implode("\n", $property->options ?? []) }}</textarea>
                                <span class="addon-end"><button type="button" class="control-button addon-button" data-field-save aria-label="{{ __('properties.save') }}" data-tooltip="{{ __('properties.save') }}" hidden><x-icon name="check" /></button></span>
                            </div>
                            <p class="field-hint">{{ __('properties.options_hint') }}</p>
                        </div>
                    @endif
                    @if ($tags->isNotEmpty())
                        <fieldset>
                            <legend>{{ __('properties.tags') }}</legend>
                            <input type="hidden" name="tags_present" value="1">
                            <div class="tags">
                                @foreach ($tags as $tag)
                                    <label class="tag-choice"><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $property->tag_ids ?? [], true))><span class="tag tag-{{ $tag->id }}">{{ $tag->name }}</span></label>
                                @endforeach
                            </div>
                            <p class="field-hint">{{ __('properties.tags_hint') }}</p>
                        </fieldset>
                    @endif
                    <noscript><button type="submit">{{ __('properties.save') }}</button></noscript>
                </form>
            </div>
        </div>
        @unless ($property->isDefault())
            <div class="dialog-backdrop" data-dialog="delete-property-{{ $property->id }}" hidden>
                <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="delete-property-{{ $property->id }}-title">
                    <h2 id="delete-property-{{ $property->id }}-title">{{ __('properties.delete_title') }}</h2>
                    <p>{{ __('properties.delete_text', ['name' => $property->name]) }}</p>
                    <div class="dialog-actions">
                        <button type="button" class="button-quiet" data-dialog-close>{{ __('properties.cancel') }}</button>
                        <form method="post" action="{{ route('properties.destroy', $property->id) }}">
                            @csrf
                            <button type="submit" class="button-danger">{{ __('properties.delete') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        @endunless
    @endforeach

    <form method="post" action="{{ route('properties.store') }}" class="property-add">
        @csrf
        <h2>{{ __('properties.add') }}</h2>
        <div class="field">
            <label for="property-new-name">{{ __('properties.name') }}</label>
            <input id="property-new-name" name="name" maxlength="60" required value="{{ old('name') }}">
        </div>
        <div class="field">
            <label for="property-new-type">{{ __('properties.type') }}</label>
            <select id="property-new-type" name="type">
                @foreach (\App\Domain\Properties\Property::TYPES as $type)
                    <option value="{{ $type }}" @selected(old('type') === $type)>{{ __('properties.types.'.$type) }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="property-new-options">{{ __('properties.options') }}</label>
            <textarea id="property-new-options" name="options" rows="3">{{ old('options') }}</textarea>
            <p class="field-hint">{{ __('properties.options_hint') }}</p>
        </div>
        <button type="submit"><x-icon name="plus" /> {{ __('properties.add') }}</button>
    </form>
@endsection
