{{-- Farbkreis (PO 2026-11-16): one colour as a circle; a click opens our own colour dialog (core/color-field.js) – never the
     browser's colour picker. The colour travels in a hidden field `name`; a change fires `change` on it (autosave forms
     save at once). <x-color-swatch name="hex" :value="$color->hex" :label="__('palette.hex')" /> --}}
@props(['name' => 'hex', 'value' => '#ff7622', 'label' => null])
<span {{ $attributes->class('color-swatch-field') }} data-color-swatch data-color-title="{{ $label ?? __('palette.own') }}" data-color-hue="{{ __('palette.dialog.hue') }}"
    data-color-r="{{ __('palette.dialog.r') }}" data-color-g="{{ __('palette.dialog.g') }}" data-color-b="{{ __('palette.dialog.b') }}"
    data-color-apply="{{ __('palette.dialog.apply') }}" data-color-cancel="{{ __('palette.dialog.cancel') }}" data-color-close="{{ __('palette.dialog.close') }}">
    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    <button type="button" class="color-swatch" aria-label="{{ $label }}: {{ $value }}" data-tooltip="{{ $label }}"></button>
</span>
