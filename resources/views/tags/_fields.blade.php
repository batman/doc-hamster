{{-- Name and colour: the shared palette (points 14-15), or an own colour (our colour dialog). Empty for a new tag = a palette colour by chance.
     $autosave (an existing tag, point 170.2): the colour saves at once; the name is changed by a click on the window title
     (PO 2026-11-16, Feld mit ✓) and rides along here as a hidden field. --}}
@php($color = old('color', $color))
@if ($autosave ?? false)
    <input type="hidden" name="name" value="{{ old('name', $name) }}">
@else
    <div class="field">
        <label for="name">{{ __('tags.name') }}</label>
        <input id="name" name="name" value="{{ old('name', $name) }}" maxlength="64" required>
    </div>
@endif

<x-color-picker name="color" :value="$color" :allow-own="true" key="tag" :legend="__('tags.color')" />
