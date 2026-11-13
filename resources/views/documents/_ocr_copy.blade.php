{{-- A281: "Alles kopieren" in the head of "Erkannter Text" – the whole text with its line breaks (modules/ocr-text.js). --}}
@if ($document->ocr_text)
    <button type="button" class="icon-button button-small ocr-copy" data-ocr-copy aria-label="{{ __('documents.words.copy_all') }}" data-tooltip="{{ __('documents.words.copy_all') }}"
        data-done="{{ __('documents.words.copied') }}" data-failed="{{ __('documents.words.copy_failed') }}"><x-icon name="copy" /></button>
@endif
