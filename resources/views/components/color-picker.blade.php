{{-- Colour picker of the framework (points 14-15): the organisation's shared palette (Einstellungen → Farben),
     optionally "keine Farbe" (value "none") and an own colour (picker + #rrggbb in "<name>_hex"). Sends the hex itself.
     A value that is no longer in the palette stays shown and chosen. $key makes the ids unique (several pickers per page). --}}
@props(['name' => 'color', 'value' => null, 'allowNone' => false, 'allowOwn' => false, 'key' => 'c', 'legend' => __('tags.color')])
@php
    $palette = \App\Domain\Palette\PaletteColor::ordered();
    $value = is_string($value) ? mb_strtolower($value) : null;
    $isHex = $value !== null && preg_match(\App\Domain\Palette\PaletteColor::HEX, $value) === 1;
    $inPalette = $isHex && $palette->contains('hex', $value);
@endphp
<fieldset {{ $attributes->class(['tag-color-field', 'color-picker']) }} @if ($allowOwn) data-color-field @endif>
    <legend>{{ $legend }}</legend>
    <div class="tag-colors">
        @if ($allowNone)
            <label class="tag-color" title="{{ __('palette.none') }}"><input type="radio" name="{{ $name }}" value="none" @checked(! $isHex) aria-label="{{ __('palette.none') }}"><span class="no-color"></span></label>
        @endif
        @if ($isHex && ! $inPalette && ! $allowOwn)
            <label class="tag-color" title="{{ $value }}"><input type="radio" name="{{ $name }}" value="{{ $value }}" checked aria-label="{{ $value }}"><span class="{{ \App\Domain\Palette\PaletteColor::cssClass($value) }}"></span></label>
        @endif
        @foreach ($palette as $color)
            <label class="tag-color" title="{{ $color->name }}"><input type="radio" name="{{ $name }}" value="{{ $color->hex }}" @checked($value === $color->hex) aria-label="{{ $color->name }}"><span class="{{ \App\Domain\Palette\PaletteColor::cssClass($color->hex) }}"></span></label>
        @endforeach
    </div>
    @if ($allowOwn)
        <div class="tag-color-own">
            <label for="{{ $name }}-picker-{{ $key }}">{{ __('palette.own') }}</label>
            <input id="{{ $name }}-picker-{{ $key }}" type="color" value="{{ $isHex ? $value : ($palette->first()?->hex ?? '#ff7622') }}" data-color-picker>
            <label for="{{ $name }}-hex-{{ $key }}" class="visually-hidden">{{ __('tags.hex') }}</label>
            <input id="{{ $name }}-hex-{{ $key }}" name="{{ $name }}_hex" value="{{ $isHex && ! $inPalette ? $value : '' }}" placeholder="#rrggbb" maxlength="7" pattern="#[0-9a-fA-F]{6}" data-color-hex>
        </div>
    @endif
</fieldset>
