{{-- One translation of the recognised text (point 172.2). --}}
<section class="ocr-translation" data-translation="{{ $to }}">
    <div class="ocr-head">
        <h4 data-translation-label>{{ $label }}</h4>
        <button type="button" class="icon-button button-small ocr-copy" data-translation-copy aria-label="{{ __('translate.copy') }}" data-tooltip="{{ __('translate.copy') }}"
            data-done="{{ __('translate.copied') }}" data-failed="{{ __('translate.copy_failed') }}"><x-icon name="copy" /></button>
    </div>
    <p class="edit-in-place-small is-plain" data-translation-text>{{ $text }}</p>
</section>
