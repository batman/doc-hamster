{{-- Reading view of the recognised text (points 121, 122.3, 122.7, 134, A240-A243, A280; modules/ocr-text.js): the words
     laid out like on the page, each one hover-linked with the preview. It replaces the plain text below as soon as the
     word positions are loaded; without them (old documents, text documents) the read-only plain text stays. Dragging
     selects text (Ctrl+C or the Auswahl-Leiste copies it); a click on a word opens the word popup. --}}
@unless ($isText)
    <div class="ocr-read" hidden data-ocr-read="{{ route('documents.words', $document->id) }}" data-document="{{ $document->id }}"
        @can('documents.edit') data-fix="{{ route('documents.words.fix', $document->id) }}" data-unfix="{{ route('documents.words.unfix', $document->id) }}" @endcan
        aria-description="{{ __('documents.words.edit_hint') }}" data-labels="{{ json_encode(__('documents.words')) }}"></div>
@endunless
