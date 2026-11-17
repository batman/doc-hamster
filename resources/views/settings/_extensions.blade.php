{{-- The Erweiterungen cards of the page "Software" (points 20, A67; was Einstellungen → Erweiterungen, point 173):
     modules (8, A63: only separately installed code – Revolut for now, "kommt später"), data packs (171: OCR languages)
     and translation, which install, update, switch off and remove; A346 re-reads broken texts. --}}
@php
    $data = array_filter($extRows, static fn (array $r): bool => $r['ext']->kind === \App\Infrastructure\Extensions\Extension::DATA);
    $ocrRows = array_filter($data, static fn (array $r): bool => $r['ext']->group !== 'translate');
    $translateRows = array_filter($data, static fn (array $r): bool => $r['ext']->group === 'translate');
    $modules = array_filter($extRows, static fn (array $r): bool => $r['ext']->kind === \App\Infrastructure\Extensions\Extension::MODULE);
@endphp
@if (session('error'))
    <p class="error" role="alert">{{ session('error') }}</p>
@endif

@include('modules._card')

{{-- PO 2026-11-17: the long lists fold and start folded; the head says which languages are on. --}}
<x-fold-card fold="extensions.ocr" :title="__('extensions.kinds.data')" icon="language" folded
    :summary="implode(', ', array_map(static fn (string $l): string => __('extensions.languages.'.$l), $languages))">
    <p class="card-text">{{ __('extensions.kind_texts.data') }} {{ __('extensions.intro') }}</p>
    {{-- 171.3: more languages = slower and slightly less exact. --}}
    <p class="card-note">{{ __('extensions.ocr_note') }} {{ __('extensions.core_languages') }}
        {{ __('extensions.active_languages', ['languages' => implode(', ', array_map(static fn (string $l): string => __('extensions.languages.'.$l), $languages))]) }}</p>
    <ul class="pick-list">
        @foreach ($ocrRows as $row)
            @include('settings._extension_row')
        @endforeach
    </ul>
</x-fold-card>

@if ($translateRows !== [])
    {{-- Point 172: translation packs; a pair brings the runtime along, "Griechisch → Deutsch" both pairs over English. --}}
    <x-fold-card fold="extensions.translate" :title="__('extensions.translate_title')" icon="language" folded>
        <p class="card-text">{{ __('extensions.translate_intro') }}</p>
        <ul class="pick-list">
            @foreach ($translateRows as $row)
                @include('settings._extension_row')
            @endforeach
        </ul>
    </x-fold-card>
@endif

@if ($broken !== null)
    {{-- A346: after a language came in, documents whose text looks broken can be read again. --}}
    <section class="card">
        <h2 class="card-head"><x-icon name="text-scan-2" />{{ __('extensions.broken_title') }}</h2>
        @if ($broken > 0)
            <p class="card-text">{{ __('extensions.broken_text') }}</p>
            <form method="post" action="{{ route('extensions.rerun') }}">
                @csrf
                <button type="submit" class="button-small"><x-icon name="refresh" />{{ __('extensions.broken_button', ['count' => $broken]) }}</button>
            </form>
        @else
            <p class="card-text muted">{{ __('extensions.broken_none') }}</p>
        @endif
    </section>
@endif

@foreach ($data as $row)
    @if ($row['installed'])
        <div class="dialog-backdrop" data-dialog="remove-extension-{{ $row['ext']->key }}" hidden>
            <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="remove-extension-{{ $row['ext']->key }}-title">
                <h2 id="remove-extension-{{ $row['ext']->key }}-title">{{ __('extensions.remove_title') }}</h2>
                <p>{{ __('extensions.remove_text', ['name' => \App\Http\Controllers\ExtensionController::name($row['ext'])]) }}</p>
                <div class="dialog-actions">
                    <form method="post" action="{{ route('extensions.remove', $row['ext']->key) }}">
                        @csrf
                        <button type="submit" class="button-danger"><x-icon name="trash" />{{ __('extensions.remove') }}</button>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endforeach
