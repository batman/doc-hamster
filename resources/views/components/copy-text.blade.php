{{-- Kopiertext (A82, A83; FRAMEWORK.md "Formulare"): a longer text to read, mark by hand or copy with the button inside the
     field – never copied by itself. <x-copy-text :id="…" :label="…" :text="…" rows="8" />; the label may be hidden. --}}
@props(['id', 'label', 'text', 'rows' => 8, 'hideLabel' => false])
<div class="field">
    <label for="{{ $id }}" @class(['visually-hidden' => $hideLabel])>{{ $label }}</label>
    <div class="field-addon is-copy is-text">
        <textarea id="{{ $id }}" rows="{{ $rows }}" readonly spellcheck="false">{{ $text }}</textarea>
        <span class="addon-end">
            <button type="button" class="control-button addon-button" data-copy="{{ $id }}" data-copied="{{ __('app.copied') }}" aria-label="{{ __('app.copy') }}" data-tooltip="{{ __('app.copy') }}"><x-icon name="copy" /></button>
        </span>
    </div>
</div>
