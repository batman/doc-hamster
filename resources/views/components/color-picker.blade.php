{{-- Colour picker of the framework (points 14-15; PO 2026-11-16): the organisation's shared palette (Einstellungen → Farben),
     optionally "keine Farbe" (value "none") and an own colour – one more circle in the same row (a radio whose value is the
     own colour, also sent as "<name>_hex"); a click opens our own colour dialog (core/color-field.js). Sends the hex itself.
     A value that is no longer in the palette stays shown and chosen. $key makes the ids unique (several pickers per page). --}}
@props(['name' => 'color', 'value' => null, 'allowNone' => false, 'allowOwn' => false, 'key' => 'c', 'legend' => __('tags.color')])
@php
    $palette = \App\Domain\Palette\PaletteColor::ordered();
    $value = is_string($value) ? mb_strtolower($value) : null;
    $isHex = $value !== null && preg_match(\App\Domain\Palette\PaletteColor::HEX, $value) === 1;
    $inPalette = $isHex && $palette->contains('hex', $value);
    $own = $isHex && ! $inPalette ? $value : null;
@endphp
<fieldset {{ $attributes->class(['tag-color-field', 'color-picker']) }}
    @if ($allowOwn) data-color-field data-color-title="{{ __('palette.own') }}" data-color-hue="{{ __('palette.dialog.hue') }}"
        data-color-r="{{ __('palette.dialog.r') }}" data-color-g="{{ __('palette.dialog.g') }}" data-color-b="{{ __('palette.dialog.b') }}"
        data-color-apply="{{ __('palette.dialog.apply') }}" data-color-cancel="{{ __('palette.dialog.cancel') }}" data-color-close="{{ __('palette.dialog.close') }}" @endif>
    <legend>{{ $legend }}</legend>
    <div class="tag-colors">
        @if ($allowNone)
            <label class="tag-color" data-tooltip="{{ __('palette.none') }}"><input type="radio" name="{{ $name }}" value="none" @checked(! $isHex) aria-label="{{ __('palette.none') }}"><span class="no-color"></span></label>
        @endif
        @if ($own !== null && ! $allowOwn)
            <label class="tag-color" data-tooltip="{{ $own }}"><input type="radio" name="{{ $name }}" value="{{ $own }}" checked aria-label="{{ $own }}"><span class="{{ \App\Domain\Palette\PaletteColor::cssClass($own) }}"></span></label>
        @endif
        @foreach ($palette as $color)
            <label class="tag-color" data-tooltip="{{ $color->name }}"><input type="radio" name="{{ $name }}" value="{{ $color->hex }}" @checked($value === $color->hex) aria-label="{{ $color->name }}"><span class="{{ \App\Domain\Palette\PaletteColor::cssClass($color->hex) }}"></span></label>
        @endforeach
        @if ($allowOwn)
            <label class="tag-color" data-tooltip="{{ __('palette.own') }}"><input type="radio" name="{{ $name }}" value="{{ $own ?? '' }}" @checked($own !== null) aria-label="{{ __('palette.own') }}" data-color-own><span class="own-color @if ($own !== null) has-color {{ \App\Domain\Palette\PaletteColor::cssClass($own) }} @endif"><x-icon name="plus" /></span></label>
        @endif
    </div>
    @if ($allowOwn)
        <input type="hidden" name="{{ $name }}_hex" value="{{ $own ?? '' }}" data-color-hex>
    @endif
</fieldset>
