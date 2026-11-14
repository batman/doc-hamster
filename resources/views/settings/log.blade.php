{{-- Einstellungen → Log (point 115, A224-A234): every logbook in one list, newest first, 50 per page. Filters (logbooks,
     kind, period, text, document) are a GET form, so the address keeps them (A230). Export = what the filter shows (A232);
     Zurücksetzen empties the ticked logbooks after a confirm (A233). Rows: FRAMEWORK pick-list with pills for logbook and kind. --}}
@extends('layouts.settings')

@section('title', __('log.title'))

@section('page')
    @php
        $query = array_filter(['books' => $filters['books'] === $books ? null : $filters['books'], 'level' => $filters['level'],
            'from' => $filters['from'], 'to' => $filters['to'], 'q' => $filters['q'], 'doc' => $filters['doc']], static fn ($v) => $v !== null && $v !== '' && $v !== []);
        $bookNames = implode(', ', array_map(static fn (string $b): string => __('log.books.'.$b), $filters['books']));
    @endphp


    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif

    @if ($summary !== [])
        {{-- A112: what text recognition cannot read, grouped by reason (only with the logbook Texterkennung alone). --}}
        {{-- A263: foldable; folded it shows the total. --}}
        <x-fold-card fold="log.ocr-summary" :title="__('ocr.log.summary')" icon="alert-triangle"
            :summary="trans_choice('ocr.summary.documents', array_sum($summary), ['count' => array_sum($summary)])" summary-tone="warning">
            <ul class="ocr-summary">
                @foreach ($summary as $reason => $count)
                    <li><strong>{{ $count }}</strong>
                        @if ($reason !== '')
                            <a href="{{ route('log.index', ['books' => ['ocr'], 'level' => 'problems', 'q' => $reason]) }}">{{ $reason }}</a>
                        @else
                            <span class="muted">{{ __('ocr.log.summary_none') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-fold-card>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="list-details" />{{ $filters['books'] === $books ? __('settings.links.log.title') : $bookNames }}
            <span class="card-action">
                <a class="button button-small button-quiet" href="{{ route('log.export', $query) }}"><x-icon name="file-export" /><span>{{ __('log.export') }}</span></a>
                <button type="button" class="button-small button-quiet" data-dialog-open="log-clear"><x-icon name="trash" /><span>{{ __('log.clear') }}</span></button>
            </span>
        </h2>
        <p class="muted">{{ __('log.keep') }}</p>

        {{-- A230: logbooks as ticks, the kind as a button group (it filters at once), period and text. --}}
        <form method="get" action="{{ route('log.index') }}" class="log-filter">
            {{-- Enter in a field keeps the chosen kind (the first submit button is the default one). --}}
            <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true" name="level" value="{{ $filters['level'] }}"></button>
            @if ($filters['doc'] !== '')
                <input type="hidden" name="doc" value="{{ $filters['doc'] }}">
            @endif
            <fieldset class="log-books">
                <legend class="visually-hidden">{{ __('log.books_label') }}</legend>
                @foreach ($books as $book)
                    <label class="check"><input type="checkbox" name="books[]" value="{{ $book }}" @checked(in_array($book, $filters['books'], true))> {{ __('log.books.'.$book) }}</label>
                @endforeach
            </fieldset>
            <div class="button-group" role="group" aria-label="{{ __('log.level_label') }}">
                @foreach (__('log.level_filter') as $value => $label)
                    <button type="submit" name="level" value="{{ $value }}" class="button-small button-quiet" aria-pressed="{{ $filters['level'] === $value ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            <label class="log-date"><span>{{ __('log.from') }}</span><input type="date" class="input-small" name="from" value="{{ $filters['from'] }}"></label>
            <label class="log-date"><span>{{ __('log.to') }}</span><input type="date" class="input-small" name="to" value="{{ $filters['to'] }}"></label>
            <input type="search" class="input-small" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('log.search') }}" aria-label="{{ __('log.search') }}">
            {{-- A305: a search button is always the magnifier with the tooltip "Suchen". --}}
            <button type="submit" class="icon-button button-small" name="level" value="{{ $filters['level'] }}" aria-label="{{ __('app.window.search_run') }}" data-tooltip="{{ __('app.window.search_run') }}"><x-icon name="search" /></button>
            @if ($query !== [])
                <a class="button button-small button-quiet" href="{{ route('log.index') }}">{{ __('log.reset_filter') }}</a>
            @endif
        </form>
        @if ($filters['doc'] !== '')
            <p class="muted">{{ __('log.document_filter', ['title' => $document ?? __('log.deleted')]) }}
                · <a href="{{ route('log.index', array_diff_key($query, ['doc' => 1])) }}">{{ __('log.reset_filter') }}</a></p>
        @endif

        @if ($rows === [])
            <p class="list-empty">{{ __('log.empty') }}</p>
        @else
            <ul class="pick-list log-list">
                @foreach ($rows as $row)
                    @php
                        $icon = match ($row['level']) {
                            'error' => 'alert-triangle',
                            'warning' => 'alert-circle',
                            default => ['general' => 'note', 'ocr' => 'text-scan-2', 'updates' => 'cloud-download'][$row['book']] ?? 'notes',
                        };
                        $details = array_filter([
                            $row['time']->timezone(config('app.timezone'))->format('d.m.Y H:i:s'),
                            $row['person'],
                            ...$row['details'],
                        ]);
                        $new = $row['level'] === 'error' && $row['time']->greaterThan($since);
                    @endphp
                    <li class="pick-row is-static log-row log-{{ $row['level'] }} @if ($new) is-marked @endif">
                        <span class="row-icon"><x-icon :name="$icon" /></span>
                        <span>
                            <strong>{{ $row['text'] }}</strong>
                            @if ($row['document_id'] !== null)
                                <small>
                                    @if ($row['document'] !== null)
                                        <a href="{{ route('documents.show', $row['document_id']) }}" data-window>{{ $row['document'] }}</a>
                                    @else
                                        {{ __('log.deleted') }}
                                    @endif
                                    @if ($filters['doc'] === '')
                                        · <a href="{{ route('log.index', [...$query, 'doc' => $row['document_id']]) }}">{{ __('log.only_document') }}</a>
                                    @endif
                                </small>
                            @endif
                            <small>{{ implode(' · ', $details) }}</small>
                        </span>
                        <span class="log-pills">
                            @if ($new)
                                <span class="pill pill-new">{{ __('log.new') }}</span>
                            @endif
                            <span class="pill">{{ __('log.books.'.$row['book']) }}</span>
                            @if ($row['level'] !== 'event')
                                <span class="pill pill-{{ $row['level'] }}">{{ __('log.levels.'.$row['level']) }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
            @include('bank._pages', ['pages' => $page])
        @endif
    </section>

    {{-- A225, A233: Zurücksetzen empties the ticked logbooks after this confirm (framework dialog). --}}
    <div class="dialog-backdrop" data-dialog="log-clear" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="log-clear-title">
            <h2 id="log-clear-title">{{ __('log.clear_title') }}</h2>
            <p>{{ __('log.clear_text', ['books' => $bookNames]) }}</p>
            <form method="post" action="{{ route('log.reset') }}">
                @csrf
                @foreach ($filters['books'] as $book)
                    <input type="hidden" name="books[]" value="{{ $book }}">
                @endforeach
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                    <button type="submit" class="button-danger">{{ __('log.clear_confirm') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
