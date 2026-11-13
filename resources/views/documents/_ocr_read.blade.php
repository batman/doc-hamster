{{-- Reading view of the recognised text (points 121, 122.3, 122.7, A240-A243; modules/ocr-text.js): the words laid out
     like on the page, each one hover-linked with the preview. It replaces the plain text below as soon as the word
     positions are loaded; without them (old documents, text documents) the plain text stays. A click beside a word
     edits the plain text (the searchable text), a click on a word opens the word popup. --}}
@unless ($isText)
    <div class="ocr-read" hidden data-ocr-read="{{ route('documents.words', $document->id) }}" data-document="{{ $document->id }}"
        @can('documents.edit') data-fix="{{ route('documents.words.fix', $document->id) }}" data-unfix="{{ route('documents.words.unfix', $document->id) }}" aria-description="{{ __('documents.words.edit_hint') }}" @endcan
        data-labels="{{ json_encode(__('documents.words')) }}"></div>
@endunless
