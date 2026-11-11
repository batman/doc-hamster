{{-- Einstellungen → Texterkennung (points 35/43, A21/A24): mode, set-up, counts, progress; refreshes itself while documents wait. --}}
@extends('layouts.settings')

@section('title', __('ocr.title'))

@section('page')
    @php
        $state = $status['state'] ?? null;
        $total = $counts['done'] + $counts['waiting'];
        $busy = $counts['waiting'] > 0 || $current !== null;
        $currentText = $current === null ? '' : ($current['percent'] !== null
            ? __('ocr.current_percent', ['title' => $current['title'], 'percent' => $current['percent']])
            : __('ocr.current', ['title' => $current['title']]));
    @endphp

    <h1>{{ __('ocr.title') }}</h1>

    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif

    <div data-ocr-page="{{ route('ocr.status') }}" data-ocr-busy="{{ $busy ? '1' : '0' }}"
        data-progress-label="{{ __('ocr.progress', ['done' => ':done', 'total' => ':total']) }}"
        data-current-label="{{ __('ocr.current', ['title' => ':title']) }}"
        data-current-percent-label="{{ __('ocr.current_percent', ['title' => ':title', 'percent' => ':percent']) }}">
        <section class="card">
            <h2 class="card-head"><x-icon name="text-scan-2" />{{ __('ocr.mode_label') }}</h2>
            <ul class="pick-list">
                <li class="pick-row is-static">
                    <x-icon :name="in_array($mode, ['system', 'app'], true) ? 'circle-check' : 'text-scan-2'" />
                    <span>
                        <strong>{{ __('ocr.mode.'.$mode) }}</strong>
                        @if ($state === 'running')
                            <small>{{ __('ocr.running_setup') }}</small>
                        @elseif ($state === 'failed' && ! in_array($mode, ['system', 'app'], true))
                            <small>{{ __('ocr.broken', ['reason' => $status['reason'] ?? '?']) }}</small>
                            @if (isset($status['browser']) && $status['browser'] !== 'ok')
                                <small>{{ __('ocr.browser_missing', ['reason' => $status['browser']]) }}</small>
                            @endif
                        @endif
                        @if ($mode === 'browser')
                            <small>{{ __('ocr.browser_hint') }}</small>
                        @endif
                    </span>
                    @if (! in_array($mode, ['system', 'app'], true) && $state !== 'running')
                        <form method="post" action="{{ route('ocr.setup') }}">
                            @csrf
                            <button type="submit" class="button-small">{{ __('ocr.set_up') }}</button>
                        </form>
                    @endif
                </li>
            </ul>
        </section>

        <section class="card">
            <h2 class="card-head"><x-icon name="file-text" />{{ __('ocr.title') }}</h2>
            <ul class="ocr-counts">
                @foreach (['done', 'waiting', 'failed', 'unavailable'] as $key)
                    <li><strong data-ocr-count="{{ $key }}">{{ $counts[$key] }}</strong><span>{{ __('ocr.counts.'.$key) }}</span></li>
                @endforeach
            </ul>
            <div class="ocr-progress" data-ocr-progress @unless ($busy) hidden @endunless>
                <progress max="{{ max(1, $total) }}" value="{{ $counts['done'] }}" data-ocr-bar></progress>
                <p data-ocr-progress-text>{{ __('ocr.progress', ['done' => $counts['done'], 'total' => $total]) }}</p>
                <p class="muted" data-ocr-current @if ($current === null) hidden @endif>{{ $currentText }}</p>
            </div>
            <form method="post" action="{{ route('ocr.rerun') }}" class="ocr-rerun">
                @csrf
                <button type="submit" class="button-quiet button-small" @if ($mode === 'none') disabled @endif>{{ __('ocr.rerun') }}</button>
            </form>
        </section>
    </div>
@endsection
