{{-- Name and colour: the shared palette (points 14-15), or any colour by picker or #rrggbb (Q-29). Empty for a new tag = a palette colour by chance.
     $autosave (an existing tag, point 170.2): the name sits in a field with ✓ (Enter or ✓ saves), the colour saves at once. --}}
@php($color = old('color', $color))
<label for="name">{{ __('tags.name') }}</label>
@if ($autosave ?? false)
    <div class="field-addon">
        <input id="name" name="name" value="{{ old('name', $name) }}" maxlength="64" required>
        <span class="addon-end"><button type="button" class="control-button addon-button" data-field-save aria-label="{{ __('tags.save') }}" data-tooltip="{{ __('tags.save') }}" hidden><x-icon name="check" /></button></span>
    </div>
@else
    <input id="name" name="name" value="{{ old('name', $name) }}" maxlength="64" required>
@endif

<x-color-picker name="color" :value="$color" :allow-own="true" key="tag" :legend="__('tags.color')" />
