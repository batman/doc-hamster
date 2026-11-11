{{--
    Click-to-edit (Go 2026-11-11, point 28; app.js "Package E"). At rest plain text with a light hover hint,
    the placeholder in grey when empty. A click (or Enter when focused) turns it into a field of the same
    size; blur or Ctrl+Enter saves (POST name + value as JSON-answering form), Escape restores the old value.
    <x-edit-in-place name="note" :value="$document->note" :action="route('documents.field', $id)" multiline placeholder="…" />
    `name` is sent as "field", the text as "value"; the endpoint answers {"value": "…"}.
--}}
@props(['name', 'value' => null, 'action', 'multiline' => false, 'placeholder' => '', 'label' => null, 'maxlength' => null])
<div {{ $attributes->class(['edit-in-place', 'is-multiline' => $multiline]) }} tabindex="0" role="button" data-edit-in-place="{{ $action }}" data-field="{{ $name }}"
    data-placeholder="{{ $placeholder }}" data-saved="{{ __('app.edit_in_place.saved') }}" data-failed="{{ __('app.edit_in_place.failed') }}"
    @if ($label) aria-label="{{ $label }}" @endif aria-description="{{ __('app.edit_in_place.hint') }}"
    @if ($maxlength) data-maxlength="{{ $maxlength }}" @endif>{{ $value }}</div>
