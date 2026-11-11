{{-- Point 61, A79-A83: the OCR log, newest first, 50 per page, filter "nur Fehler". Rows: FRAMEWORK pick-list. --}}
<section class="card">
    <h2 class="card-head"><x-icon name="list-details" />{{ __('ocr.tabs.log') }}</h2>
    <p class="muted">
        {{ __('ocr.log.keep') }}
        @if ($onlyErrors)
            <a href="{{ route('ocr.show', ['tab' => 'log']) }}">{{ __('ocr.log.all') }}</a>
        @else
            <a href="{{ route('ocr.show', ['tab' => 'log', 'errors' => 1]) }}">{{ __('ocr.log.only_errors') }}</a>
        @endif
    </p>
    @if ($log === null || $log->isEmpty())
        <p class="list-empty">{{ __('ocr.log.empty') }}</p>
    @else
        <ul class="pick-list">
            @foreach ($log as $entry)
                @php
                    $isError = in_array($entry->event, \App\Domain\Ocr\OcrLog::ERRORS, true);
                    $icon = match (true) {
                        $isError => 'alert-triangle',
                        $entry->event === 'recognized' => 'circle-check',
                        $entry->event === 'paused' => 'player-pause',
                        $entry->event === 'resumed' => 'player-play',
                        str_starts_with($entry->event, 'cron') => 'clock',
                        default => 'settings',
                    };
                    $eventKey = 'ocr.log.event.'.$entry->event;
                    $result = \Illuminate\Support\Facades\Lang::has($eventKey)
                        ? __($eventKey, ['chars' => (int) $entry->chars, 'message' => $entry->message ?? '?'])
                        : $entry->event;
                    $details = array_filter([
                        $entry->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i:s'),
                        $entry->pass !== null ? __('ocr.log.pass', ['pass' => $entry->pass]) : null,
                        __('ocr.log.source.'.$entry->source),
                        $entry->duration_ms !== null ? __('ocr.log.seconds', ['seconds' => number_format($entry->duration_ms / 1000, 1, ',', '.')]) : null,
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
                            </strong>
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
