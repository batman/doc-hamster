{{-- Name and colour: the shared palette (points 14-15), or any colour by picker or #rrggbb (Q-29). Empty for a new tag = a palette colour by chance. --}}
@php($color = old('color', $color))
<label for="name">{{ __('tags.name') }}</label>
<input id="name" name="name" value="{{ old('name', $name) }}" maxlength="64" required>

<x-color-picker name="color" :value="$color" :allow-own="true" key="tag" :legend="__('tags.color')" />
