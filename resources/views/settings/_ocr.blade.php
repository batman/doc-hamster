{{-- The Texterkennung page's content (points 35/43/61, A21/A24; points 123-125, A255-A263): shown in Einstellungen
     (settings/ocr) and as a module of its own from the OCR widget (ocr/page). Order (A262): 1. Texterkennung with
     progress, 2. Live, 3. Art (folded), 4. Hintergrundaufgaben (folded). --}}
@php
    $state = $status['state'] ?? null;
    $total = $counts['done'] + $counts['waiting'];
    $busy = $counts['waiting'] > 0 || $current !== null;
    $currentText = $current === null ? '' : ($current['percent'] !== null
        ? __('ocr.current_percent', ['title' => $current['title'], 'percent' => $current['percent']])
        : __('ocr.current', ['title' => $current['title']]));
    $modeOk = in_array($mode, ['system', 'app'], true) && $state !== 'failed';
    $modeTone = match (true) {
        $modeOk => 'ok',
        $mode === 'none', $engineError !== null => 'warning',
        default => null,
    };
@endphp

<p><a href="{{ route('log.ocr') }}"><x-icon name="list-details" /> {{ __('settings.links.log_ocr.title') }}: {{ __('log.title') }}</a></p>

@if (session('status'))
    <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
@endif

<div data-ocr-page="{{ route('ocr.status', ['live' => 1]) }}" data-ocr-busy="{{ $busy ? '1' : '0' }}"
    data-progress-label="{{ __('ocr.progress', ['done' => ':done', 'total' => ':total']) }}"
    data-current-label="{{ __('ocr.current', ['title' => ':title']) }}"
    data-current-percent-label="{{ __('ocr.current_percent', ['title' => ':title', 'percent' => ':percent']) }}">
    <x-fold-card fold="ocr.progress" :title="__('ocr.title')" icon="file-text">
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
    </x-fold-card>

    @include('settings._ocr_live')

    <x-fold-card fold="ocr.mode" :title="__('ocr.mode_label')" icon="text-scan-2" :folded="$mode !== 'none'"
        :summary="__('ocr.mode.'.$mode)" :summary-tone="$modeTone">
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
            @if ($server['engine'] === 'app' && ! $pdfReady && $state !== 'running')
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
            @if ($server['engine'] === null && $state !== 'running')
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
    </x-fold-card>

    {{-- Point 186.1/186.2: the server's own recognition – installed, version, languages, on/off (saves at once) and
         "Server testen" (the server reads a built-in sample page; result inline, modules/ocr-server.js). --}}
    <x-fold-card fold="ocr.server" :title="__('ocr.server.title')" icon="server" :folded="$server['engine'] === null"
        :summary="__($server['engine'] === null ? 'ocr.server.summary_none' : ($server['on'] ? 'ocr.server.summary_on' : 'ocr.server.summary_off'))"
        :summary-tone="$server['engine'] !== null && $server['on'] ? 'ok' : null">
        <ul class="pick-list">
            <li class="pick-row is-static">
                <x-icon :name="$server['engine'] !== null ? 'circle-check' : 'circle-x'" />
                <span>
                    <strong>{{ __('ocr.server.installed') }}: {{ __($server['engine'] !== null ? 'ocr.server.yes' : 'ocr.server.no') }}</strong>
                    @if ($server['engine'] !== null)
                        <small>{{ __('ocr.server.engine.'.$server['engine']) }} · {{ __('ocr.server.version') }}: {{ $server['version'] ?? __('ocr.server.unknown') }}</small>
                        <small>{{ __('ocr.server.languages') }}: {{ implode(', ', $server['languages']) }}</small>
                        <small>{{ __('ocr.server.pdf') }}: {{ __($server['pdf'] ? 'ocr.server.pdf_yes' : 'ocr.server.pdf_no') }}</small>
                    @else
                        <small>{{ __('ocr.server.none_hint') }}</small>
                    @endif
                </span>
            </li>
        </ul>
        @if ($server['engine'] !== null)
            <form method="post" action="{{ route('ocr.server') }}" data-autosave>
                @csrf
                <fieldset class="pick-list">
                    <legend>{{ __('ocr.server.use') }}</legend>
                    @foreach ([1 => 'on', 0 => 'off'] as $value => $key)
                        <label class="pick-row">
                            <input type="radio" class="visually-hidden" name="server" value="{{ $value }}" @checked($server['on'] === (bool) $value)>
                            <span><strong>{{ __('ocr.server.choice.'.$key.'.title') }}</strong><small>{{ __('ocr.server.choice.'.$key.'.text') }}</small></span>
                            <span class="pick-mark"><x-icon name="check" /></span>
                        </label>
                    @endforeach
                </fieldset>
                <noscript><button type="submit">{{ __('ocr.server.save') }}</button></noscript>
            </form>
            <div data-ocr-server-test="{{ route('ocr.test') }}" data-running="{{ __('ocr.server.test_running') }}"
                data-result="{{ __('ocr.server.test_result', ['found' => ':found', 'total' => ':total', 'words' => ':words', 'seconds' => ':seconds']) }}">
                <p class="muted">{{ __('ocr.server.test_hint') }}</p>
                <button type="button" class="button-small" data-ocr-server-run><x-icon name="player-play" /><span>{{ __('ocr.server.test') }}</span></button>
                <ul class="pick-list" data-ocr-server-out hidden>
                    <li class="pick-row is-static" data-ocr-server-row>
                        <x-icon name="text-scan-2" />
                        <span><strong data-ocr-server-summary></strong></span>
                    </li>
                    <li class="pick-row is-static">
                        <x-icon name="file-text" />
                        <span><strong>{{ __('ocr.server.expected') }}</strong><span data-ocr-server-expected></span></span>
                    </li>
                    <li class="pick-row is-static">
                        <x-icon name="text-scan-2" />
                        <span><strong>{{ __('ocr.server.recognized') }}</strong><span data-ocr-server-text></span></span>
                    </li>
                </ul>
                <template data-ocr-server-ok><x-icon name="circle-check" /></template>
                <template data-ocr-server-warn><x-icon name="alert-triangle" /></template>
            </div>
        @endif
    </x-fold-card>

    {{-- A67/A71/A75: is cron running? Without it the open app works off the queue. --}}
    <x-fold-card fold="ocr.cron" :title="__('ocr.cron_label')" icon="clock" folded
        :summary="__($cronRunning ? 'ocr.summary.cron_ok' : 'ocr.summary.cron_missing')" :summary-tone="$cronRunning ? 'ok' : 'warning'">
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
    </x-fold-card>
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
