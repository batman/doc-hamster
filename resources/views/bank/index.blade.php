@extends('layouts.app')

@section('title', __('bank.title'))

@section('main_class', 'page page-split')

@section('content')
    <div class="split-main">
    <div class="page-head"><h1>{{ __('bank.title') }}</h1></div>
    {{-- The views are register tabs: the chosen one opens the strip's hairline into the list below. --}}
    <nav class="tabs" aria-label="{{ __('bank.title') }}">
        @foreach ($views as $name)
            <a href="{{ route('bank.index', ['view' => $name]) }}" @if ($name === $view) aria-current="page" @endif>{{ __('bank.views.'.$name) }}</a>
        @endforeach
    </nav>

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    @if ($documents !== null)
        @if ($documents->isEmpty())
            <p class="muted">{{ __('bank.empty.receipts') }}</p>
        @else
            <ul class="documents">
                @foreach ($documents as $document)
                    @include('bank._receipt')
                @endforeach
            </ul>
            @include('bank._pages', ['pages' => $documents])
        @endif
    @elseif ($transactions->isEmpty())
        <p class="muted">{{ __('bank.empty.'.$view) }}</p>
    @else
        {{-- The statement as a ledger: date, who, amount; account, receipts and purpose below. --}}
        <ul class="ledger">
            @foreach ($transactions as $entry)
                <li @if ($transaction?->id === $entry->id) class="is-open" @endif>
                    <a href="{{ route('bank.show', [$entry->id, ...request()->query()]) }}">
                        <span class="ledger-date">{{ $entry->booking_date->format('d.m.Y') }}</span>
                        <span class="ledger-who">{{ $entry->counterparty }}</span>
                        <span class="ledger-amount @if ($entry->amount_minor > 0) is-in @endif">{{ $entry->formattedAmount() }}</span>
                        <span class="ledger-meta">
                            {{ $entry->account->name }}
                            @if ($entry->matches_count > 0)
                                · {{ trans_choice('bank.receipts_count', $entry->matches_count, ['count' => $entry->matches_count]) }}
                            @endif
                        </span>
                        @if ($entry->purpose)
                            <span class="ledger-purpose">{{ $entry->purpose }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
        @include('bank._pages', ['pages' => $transactions])
    @endif
    </div>

    @if ($transaction)
        @include('bank._detail')
    @endif
@endsection
