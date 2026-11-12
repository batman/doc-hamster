{{-- Points 61/69, A79-A83/A106-A112: the OCR log, newest first, 50 per page. On top: "nicht möglich" by reason, then filters (result, source, document, search). Rows: FRAMEWORK pick-list. --}}
@php
    $base = ['tab' => 'log'];
    $active = array_filter($filters, static fn ($v) => $v !== '');
    $formatSize = static function (?int $bytes): ?string {
        if ($bytes === null) {
            return null;
        }
        if ($bytes < 1024 * 1024) {
            return number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.').' KB';
        }

        return number_format($bytes / 1024 / 1024, 1, ',', '.').' MB';
    };
@endphp

@if ($summary !== [])
    {{-- A112: what cannot be read, grouped by reason. --}}
    <section class="card">
        <h2 class="card-head"><x-icon name="alert-triangle" />{{ __('ocr.log.summary') }}</h2>
        <ul class="ocr-summary">
            @foreach ($summary as $reason => $count)
                <li>
                    <strong>{{ $count }}</strong>
                    @if ($reason !== '')
                        <a href="{{ route('ocr.show', [...$base, 'result' => 'unavailable', 'q' => $reason]) }}">{{ $reason }}</a>
                    @else
                        <span class="muted">{{ __('ocr.log.summary_none') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif

<section class="card">
    <h2 class="card-head"><x-icon name="list-details" />{{ __('ocr.tabs.log') }}</h2>
    <p class="muted">{{ __('ocr.log.keep') }}</p>

    {{-- A110: filters as a plain GET form. --}}
    <form method="get" action="{{ route('ocr.show') }}" class="ocr-filter">
        <input type="hidden" name="tab" value="log">
        @if ($filters['doc'] !== '')
            <input type="hidden" name="doc" value="{{ $filters['doc'] }}">
        @endif
        <select name="result" class="input-small" aria-label="{{ __('ocr.log.any_result') }}">
            <option value="">{{ __('ocr.log.any_result') }}</option>
            @foreach (array_keys(\App\Domain\Ocr\OcrLog::RESULTS) as $result)
                <option value="{{ $result }}" @selected($filters['result'] === $result)>{{ __('ocr.log.result.'.$result) }}</option>
            @endforeach
        </select>
        <select name="engine" class="input-small" aria-label="{{ __('ocr.log.any_engine') }}">
            <option value="">{{ __('ocr.log.any_engine') }}</option>
            @foreach (\App\Domain\Ocr\OcrLog::SOURCES as $source)
                <option value="{{ $source }}" @selected($filters['engine'] === $source)>{{ __('ocr.log.source.'.$source) }}</option>
            @endforeach
        </select>
        <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('ocr.log.search') }}" aria-label="{{ __('ocr.log.search') }}">
        <button type="submit" class="button-small">{{ __('ocr.log.filter') }}</button>
        @if ($active !== [])
            <a class="button-quiet button-small" href="{{ route('ocr.show', $base) }}">{{ __('ocr.log.reset') }}</a>
        @endif
    </form>
    @if ($filters['doc'] !== '')
        <p class="muted">{{ __('ocr.log.document_filter', ['title' => $filterDocument ?? __('ocr.log.deleted')]) }}
            · <a href="{{ route('ocr.show', array_filter([...$base, ...$filters, 'doc' => ''], static fn ($v) => $v !== '')) }}">{{ __('ocr.log.reset') }}</a></p>
    @endif

    @if ($log === null || $log->isEmpty())
        <p class="list-empty">{{ __('ocr.log.empty') }}</p>
    @else
        <ul class="pick-list">
            @foreach ($log as $entry)
                @php
                    $isError = in_array($entry->event, \App\Domain\Ocr\OcrLog::ERRORS, true);
                    $icon = match (true) {
                        $isError => 'alert-triangle',
                        $entry->event === 'unavailable' => 'file-off',
                        in_array($entry->event, ['recognized', 'text_layer'], true) => 'circle-check',
                        $entry->event === 'paused' => 'player-pause',
                        $entry->event === 'resumed' => 'player-play',
                        str_starts_with($entry->event, 'cron') => 'clock',
                        default => 'settings',
                    };
                    $eventKey = 'ocr.log.event.'.$entry->event;
                    $result = \Illuminate\Support\Facades\Lang::has($eventKey)
                        ? rtrim(__($eventKey, ['chars' => (int) $entry->chars, 'message' => $entry->message ?? '']), ' ·:')
                        : $entry->event;
                    $file = array_filter([
                        $entry->file_type,
                        $formatSize($entry->size),
                        $entry->pages !== null ? trans_choice('ocr.log.pages', $entry->pages, ['count' => $entry->pages]) : null,
                    ]);
                    $details = array_filter([
                        $entry->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s'),
                        __('ocr.log.source.'.$entry->source),
                        $entry->pass !== null ? __('ocr.log.pass', ['pass' => $entry->pass]) : null,
                        $entry->duration_ms !== null ? __('ocr.log.seconds', ['seconds' => number_format($entry->duration_ms / 1000, 1, ',', '.')]) : null,
                        $entry->user?->display_name,
                        $entry->agent,
                    ]);
                @endphp
                <li class="pick-row is-static">
                    <x-icon :name="$icon" />
                    <span>
                        @if ($entry->document_id !== null)
                            <strong>
                                @if ($entry->document !== null)
                                    <a href="{{ route('documents.show', $entry->document_id) }}" data-window>{{ $entry->document->title }}</a>
                                @else
                                    {{ __('ocr.log.deleted') }}
                                @endif
                                @if ($filters['doc'] === '')
                                    <a class="ocr-doc-filter" href="{{ route('ocr.show', [...$base, 'doc' => $entry->document_id]) }}" title="{{ __('ocr.log.only_document') }}" aria-label="{{ __('ocr.log.only_document') }}"><x-icon name="filter" /></a>
                                @endif
                            </strong>
                            @if ($file !== [])
                                <small>{{ implode(' · ', $file) }}</small>
                            @endif
                            <small @class(['error' => $isError])>{{ $result }}</small>
                        @else
                            <strong @class(['error' => $isError])>{{ $result }}</strong>
                        @endif
                        <small>{{ implode(' · ', $details) }}</small>
                    </span>
                </li>
            @endforeach
        </ul>
        @include('bank._pages', ['pages' => $log])
    @endif
</section>
