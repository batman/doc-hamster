{{-- Einstellungen → Texterkennung (points 35/43/61, A21/A24): tab Übersicht (mode, set-up, counts, progress; refreshes itself while documents wait);
     the log moved to Einstellungen → Log, logbook "Texterkennung" (point 116). --}}
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
    <p><a href="{{ route('log.ocr') }}"><x-icon name="list-details" /> {{ __('settings.links.log_ocr.title') }}: {{ __('log.title') }}</a></p>

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
                        @endif
                        @if ($mode === 'browser')
                            <small>{{ __('ocr.browser_hint') }}</small>
                            @if ($engineError !== null)
                                <small class="error">{{ __('ocr.engine_error', ['reason' => $engineError]) }}</small>
                            @endif
                        @endif
                        @if ($mode === 'app' && ! $pdfReady)
                            <small>{{ __('ocr.pdf_hint') }}</small>
                            @if (($status['pdf'] ?? null) === 'failed')
                                <small class="error">{{ __('ocr.log.pdf_failed', ['reason' => $status['pdf_reason'] ?? '?']) }}</small>
                            @endif
                        @endif
                    </span>
                </li>
                @if ($mode === 'app' && ! $pdfReady && $state !== 'running')
                    {{-- Point 69/A113: adds the PDF converter to a working app-owned Tesseract. --}}
                    <li class="pick-row is-static">
                        <x-icon name="download" />
                        <span>
                            <strong>{{ __('ocr.set_up_pdf') }}</strong>
                            <small>{{ __('ocr.set_up_pdf_hint') }}</small>
                        </span>
                        <form method="post" action="{{ route('ocr.setup') }}" data-ocr-setup>
                            @csrf
                            <button type="submit" class="button-small">{{ __('ocr.set_up_pdf') }}</button>
                        </form>
                    </li>
                @endif
                @if (! in_array($mode, ['system', 'app'], true) && $state !== 'running')
                    {{-- A65/A68: runs in the request; JS shows the progress dialog, without JS the page just waits. --}}
                    <li class="pick-row is-static">
                        <x-icon name="download" />
                        <span>
                            <strong>{{ __('ocr.set_up') }}</strong>
                            <small>{{ __('ocr.set_up_hint') }}</small>
                        </span>
                        <form method="post" action="{{ route('ocr.setup') }}" data-ocr-setup>
                            @csrf
                            <button type="submit" class="button-small">{{ __('ocr.set_up') }}</button>
                        </form>
                    </li>
                @endif
            </ul>
        </section>

        {{-- A67/A71/A75: is cron running? Without it the open app works off the queue. --}}
        <section class="card">
            <h2 class="card-head"><x-icon name="clock" />{{ __('ocr.cron_label') }}</h2>
            <ul class="pick-list">
                <li class="pick-row is-static">
                    <x-icon :name="$cronRunning ? 'circle-check' : 'alert-triangle'" />
                    <span>
                        @if ($cronRunning)
                            <strong>{{ __('ocr.cron_running', ['time' => \Illuminate\Support\Carbon::createFromTimestamp((int) $cronLast, config('app.timezone'))->format('H:i')]) }}</strong>
                        @else
                            <strong>{{ __('ocr.cron_missing') }}</strong>
                            <small>{{ __('ocr.cron_hint') }}</small>
                            <small><code>{{ $cronLine }}</code></small>
                        @endif
                    </span>
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

    {{-- Progress dialog (FRAMEWORK §5): not closable while the set-up runs. --}}
    <div class="dialog-backdrop" data-ocr-setup-progress hidden data-failed="{{ __('ocr.setup_failed') }}">
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="ocr-setup-title">
            <h2 id="ocr-setup-title">{{ __('ocr.setup_title') }}</h2>
            <progress data-progress-bar></progress>
            <p role="status" data-progress-step>{{ __('ocr.setup_step') }}</p>
            <div class="dialog-actions" data-progress-actions hidden>
                <button type="button" data-progress-close>{{ __('ocr.setup_close') }}</button>
            </div>
        </div>
    </div>
@endsection
