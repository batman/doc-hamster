{{-- Einstellungen → Log (point 115, A224-A234): every logbook in one list, newest first, 50 per page. Filters (logbooks,
     kind, period, text, document) are a GET form, so the address keeps them (A230). Export = what the filter shows (A232);
     Zurücksetzen empties the ticked logbooks after a confirm (A233). Rows: FRAMEWORK pick-list with pills for logbook and kind. --}}
@extends('layouts.settings')

@section('title', __('log.title'))

@section('page')
    @php
        $query = array_filter(['books' => $filters['books'] === $books ? null : $filters['books'], 'level' => $filters['level'],
            'from' => $filters['from'], 'to' => $filters['to'], 'q' => $filters['q'], 'doc' => $filters['doc'], 'engine' => $filters['engine']], static fn ($v) => $v !== null && $v !== '' && $v !== []);
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
                            <a href="{{ route('log.index', ['books' => ['ocr'], 'level' => 'hints', 'q' => $reason]) }}">{{ $reason }}</a>
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
                <a class="icon-button" href="{{ route('log.export', $query) }}" aria-label="{{ __('log.export') }}" data-tooltip="{{ __('log.export') }}"><x-icon name="file-export" /></a>
                <button type="button" class="icon-button" data-dialog-open="log-clear" aria-label="{{ __('log.clear') }}" data-tooltip="{{ __('log.clear') }}"><x-icon name="trash" /></button>
            </span>
        </h2>
        <p class="muted">{{ __('log.keep') }}</p>

        {{-- A230: logbooks as ticks, the kind as a button group (it filters at once), period and text. --}}
        <form method="get" action="{{ route('log.index') }}" class="log-filter">
            {{-- Enter in a field keeps the chosen kind (the first submit button is the default one). --}}
            <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true" name="level" value="{{ $filters['level'] }}"></button>
            {{-- 186.4: kind and engine stay when the other group is clicked (the pressed button comes later and wins). --}}
            <input type="hidden" name="level" value="{{ $filters['level'] }}">
            <input type="hidden" name="engine" value="{{ $filters['engine'] }}">
            @if ($filters['doc'] !== '')
                <input type="hidden" name="doc" value="{{ $filters['doc'] }}">
            @endif
            {{-- PO 2026-11-16: the logbooks are a Mehrfachauswahl (the rail with boxes). --}}
            <div class="chips chips-small" role="group" aria-label="{{ __('log.books_label') }}">
                @foreach ($books as $book)
                    <label class="chip"><input type="checkbox" name="books[]" value="{{ $book }}" @checked(in_array($book, $filters['books'], true))><span>{{ __('log.books.'.$book) }}</span></label>
                @endforeach
            </div>
            @php $levelIcons = ['errors' => 'alert-triangle', 'hints' => 'alert-circle']; @endphp
            <div class="switcher" role="group" aria-label="{{ __('log.level_label') }}">
                @foreach (__('log.level_filter') as $value => $label)
                    <button type="submit" name="level" value="{{ $value }}" aria-pressed="{{ $filters['level'] === $value ? 'true' : 'false' }}">@if ($levelIcons[$value] ?? null)<x-icon :name="$levelIcons[$value]" />@endif{{ $label }}</button>
                @endforeach
            </div>
            @if (in_array('ocr', $filters['books'], true))
                {{-- 186.4: who read it – server or browser (only the Texterkennung logbook); an Umschalter (a choice). --}}
                @php $engineIcons = ['server' => 'server', 'browser' => 'device-laptop']; @endphp
                <div class="switcher" role="group" aria-label="{{ __('log.engine_label') }}">
                    @foreach (__('log.engine_filter') as $value => $label)
                        <button type="submit" name="engine" value="{{ $value }}" aria-pressed="{{ $filters['engine'] === $value ? 'true' : 'false' }}">@if ($engineIcons[$value] ?? null)<x-icon :name="$engineIcons[$value]" />@endif{{ $label }}</button>
                    @endforeach
                </div>
            @endif
            {{-- Von – bis as the framework's Suchzeile, the text search as a Feld mit Symbol (A305: the magnifier searches). --}}
            <span class="search-row">
                <input type="date" class="input-small" name="from" value="{{ $filters['from'] }}" aria-label="{{ __('log.from') }}" data-tooltip="{{ __('log.from') }}">
                <span class="muted">–</span>
                <input type="date" class="input-small" name="to" value="{{ $filters['to'] }}" aria-label="{{ __('log.to') }}" data-tooltip="{{ __('log.to') }}">
            </span>
            <div class="field-addon log-search">
                <input type="search" class="input-small" name="q" value="{{ $filters['q'] }}" placeholder="{{ __('log.search') }}" aria-label="{{ __('log.search') }}">
                <span class="addon-end"><button type="submit" class="control-button addon-button" name="level" value="{{ $filters['level'] }}" aria-label="{{ __('app.window.search_run') }}" data-tooltip="{{ __('app.window.search_run') }}"><x-icon name="search" /></button></span>
            </div>
            @if ($query !== [])
                <a class="button button-small button-quiet" href="{{ route('log.index') }}">{{ __('log.reset_filter') }}</a>
            @endif
        </form>
        @if ($filters['doc'] !== '')
            <p class="muted">{{ __('log.document_filter', ['title' => $document ?? __('log.deleted')]) }}
                · <a href="{{ route('log.index', array_diff_key($query, ['doc' => 1])) }}">{{ __('log.reset_filter') }}</a></p>
        @endif

        @if ($rows === [])
            <x-empty icon="list" :text="$query !== [] ? __('empty.log_filtered') : __('empty.log')" />
        @else
            <ul class="pick-list">
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
                            @if ($row['help'] !== null)
                                {{-- Batch 16 (2.5): what a critical entry means and what to do. --}}
                                <small class="log-help">{{ $row['help'] }}</small>
                            @endif
                            <small>{{ implode(' · ', $details) }}</small>
                            @if (($row['body'] ?? null) !== null)
                                {{-- A82: the long part (e.g. an upload protocol), folded; read, mark or copy it. --}}
                                <x-fold-card class="log-body" :fold="'log.body.'.$row['id']" :title="__('log.body')" icon="list-details" compact folded :remember="false">
                                    <x-copy-text :id="'log-body-'.$row['id']" :label="__('log.body')" :text="$row['body']" rows="10" hide-label />
                                </x-fold-card>
                            @endif
                        </span>
                        <span class="log-pills">
                            @if ($new)
                                <span class="pill pill-new">{{ __('log.new') }}</span>
                            @endif
                            <span class="pill">{{ __('log.books.'.$row['book']) }}</span>
                            @if ($row['level'] !== 'event')
                                <span class="pill {{ $row['level'] === 'error' ? 'pill-error' : 'pill-hint' }}">{{ __('log.levels.'.$row['level']) }}</span>
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
                    <button type="submit" class="button-danger">{{ __('log.clear_confirm') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
