{{-- Einstellungen → Erweiterungen (point 173): everything optional on one page. Data packs (171: OCR languages) install,
     update, switch off and remove; code modules are listed as "kommt später" (173.1 b). A346: re-read broken texts. --}}
@php
    $data = array_filter($rows, static fn (array $r): bool => $r['ext']->kind === \App\Infrastructure\Extensions\Extension::DATA);
    $ocrRows = array_filter($data, static fn (array $r): bool => $r['ext']->group !== 'translate');
    $translateRows = array_filter($data, static fn (array $r): bool => $r['ext']->group === 'translate');
    $modules = array_filter($rows, static fn (array $r): bool => $r['ext']->kind === \App\Infrastructure\Extensions\Extension::MODULE);
@endphp
@extends('layouts.settings')

@section('title', __('extensions.title'))

@section('page')
    <p class="muted">{{ __('extensions.intro') }}</p>
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif
    @if (session('error'))
        <p class="error" role="alert">{{ session('error') }}</p>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="language" />{{ __('extensions.kinds.data') }}</h2>
        <p class="card-text">{{ __('extensions.kind_texts.data') }}</p>
        {{-- 171.3: more languages = slower and slightly less exact. --}}
        <p class="card-note">{{ __('extensions.ocr_note') }} {{ __('extensions.core_languages') }}
            {{ __('extensions.active_languages', ['languages' => implode(', ', array_map(static fn (string $l): string => __('extensions.languages.'.$l), $languages))]) }}</p>
        <ul class="pick-list">
            @foreach ($ocrRows as $row)
                @include('settings._extension_row')
            @endforeach
        </ul>
    </section>

    @if ($translateRows !== [])
        {{-- Point 172: translation packs; a pair brings the runtime along, "Griechisch → Deutsch" both pairs over English. --}}
        <section class="card">
            <h2 class="card-head"><x-icon name="language" />{{ __('extensions.translate_title') }}</h2>
            <p class="card-text">{{ __('extensions.translate_intro') }}</p>
            <ul class="pick-list">
                @foreach ($translateRows as $row)
                    @include('settings._extension_row')
                @endforeach
            </ul>
        </section>
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

    <section class="card">
        <h2 class="card-head"><x-icon name="puzzle" />{{ __('extensions.kinds.module') }}</h2>
        <p class="card-text">{{ __('extensions.kind_texts.module') }}</p>
        <ul class="pick-list">
            @foreach ($modules as $row)
                <li class="pick-row is-static">
                    <x-icon name="puzzle" />
                    <span>
                        <strong>{{ \App\Http\Controllers\ExtensionController::name($row['ext']) }}</strong>
                        <small>{{ \App\Http\Controllers\ExtensionController::text($row['ext']) }} · {{ __('extensions.later') }}</small>
                    </span>
                    <span class="pill">{{ __('extensions.later_pill') }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    @foreach ($data as $row)
        @if ($row['installed'])
            <div class="dialog-backdrop" data-dialog="remove-extension-{{ $row['ext']->key }}" hidden>
                <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="remove-extension-{{ $row['ext']->key }}-title">
                    <h2 id="remove-extension-{{ $row['ext']->key }}-title">{{ __('extensions.remove_title') }}</h2>
                    <p>{{ __('extensions.remove_text', ['name' => \App\Http\Controllers\ExtensionController::name($row['ext'])]) }}</p>
                    <div class="dialog-actions">
                        <button type="button" class="button-quiet" data-dialog-close>{{ __('extensions.cancel') }}</button>
                        <form method="post" action="{{ route('extensions.remove', $row['ext']->key) }}">
                            @csrf
                            <button type="submit" class="button-danger"><x-icon name="trash" />{{ __('extensions.remove') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endforeach
@endsection
