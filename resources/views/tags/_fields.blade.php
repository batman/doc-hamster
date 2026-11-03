{{-- Name and colour: the six colours of the design, or any colour by picker or #rrggbb (Q-29). Empty for a new tag = a palette colour by chance. --}}
@php($color = old('color', $color))
<label for="name">{{ __('tags.name') }}</label>
<input id="name" name="name" value="{{ old('name', $name) }}" maxlength="64" required>

<fieldset class="tag-color-field" data-color-field>
    <legend>{{ __('tags.color') }}</legend>
    <div class="tag-colors">
        @foreach (\App\Domain\Documents\Tag::PALETTE as $n => $hex)
            <label class="tag-color" title="{{ $hex }}"><input type="radio" name="color" value="{{ $hex }}" @checked($color === $hex) aria-label="{{ $hex }}"><span class="tag-c{{ $n + 1 }}"></span></label>
        @endforeach
    </div>
    <div class="tag-color-own">
        <label for="color-picker">{{ __('tags.own_color') }}</label>
        <input id="color-picker" type="color" value="{{ $color ?? \App\Domain\Documents\Tag::PALETTE[0] }}" data-color-picker>
        <label for="color-hex" class="visually-hidden">{{ __('tags.hex') }}</label>
        <input id="color-hex" name="color_hex" value="{{ $color !== null && ! in_array($color, \App\Domain\Documents\Tag::PALETTE, true) ? $color : '' }}" placeholder="#rrggbb" maxlength="7" pattern="#[0-9a-fA-F]{6}" data-color-hex>
    </div>
</fieldset>
