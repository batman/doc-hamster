{{-- Point 172.2: the stored translations of the recognised text under the original, read-only and copyable; the
     template is what modules/translate.js fills after "Übersetzen". --}}
@if ($document->ocr_text)
    <div class="ocr-translations" data-translations>
        @foreach ($document->translations()->orderBy('to_lang')->get() as $translation)
            @include('documents._translation', ['to' => $translation->to_lang, 'label' => __('translate.heading', ['language' => __('translate.languages.'.$translation->to_lang)]), 'text' => $translation->text])
        @endforeach
        <template>@include('documents._translation', ['to' => '', 'label' => '', 'text' => ''])</template>
    </div>
@endif
