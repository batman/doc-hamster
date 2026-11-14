{{-- Kopierfeld (FRAMEWORK.md): a fixed value with a copy symbol inside. Empty with a placeholder: nothing to copy. --}}
<div class="field">
    <label for="{{ $id }}">{{ $label }}</label>
    <div class="field-addon is-copy">
        <input id="{{ $id }}" value="{{ $value }}" readonly @isset($placeholder) placeholder="{{ $placeholder }}" @endisset>
        @if ($value !== '')
            <span class="addon-end">
                <button type="button" class="control-button addon-button" data-copy="{{ $id }}" data-copied="{{ __('devices.copied') }}" aria-label="{{ __('devices.copy') }}" data-tooltip="{{ __('devices.copy') }}"><x-icon name="copy" /></button>
            </span>
        @endif
    </div>
</div>
