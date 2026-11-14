{{-- Point 172.2: "Übersetzen" in the head of "Erkannter Text" with the target languages the installed packs reach
     (directly or over English); modules/translate.js runs it in the browser. Nothing installed = no button. --}}
@can('documents.edit')
    @php($translate = $document->ocr_text ? \App\Http\Controllers\TranslationController::config($document) : null)
    @if ($translate)
        <span class="ocr-translate" data-translate="{{ json_encode($translate) }}"
            data-labels="{{ json_encode(['running' => __('translate.running'), 'failed' => __('translate.failed')]) }}">
            @if (count($translate['targets']) > 1)
                <select class="input-small" aria-label="{{ __('translate.target') }}">
                    @foreach ($translate['targets'] as $to => $target)
                        <option value="{{ $to }}">{{ $target['label'] }}</option>
                    @endforeach
                </select>
            @endif
            <button type="button" class="button-quiet button-small" data-translate-run
                data-tooltip="{{ __('translate.button') }}{{ count($translate['targets']) === 1 ? ' · '.reset($translate['targets'])['label'] : '' }} – {{ __('translate.replace') }}">
                <x-icon name="language" />{{ __('translate.button') }}
            </button>
        </span>
    @endif
@endcan
