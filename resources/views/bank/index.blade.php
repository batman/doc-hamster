@extends('layouts.app')

@section('title', __('bank.title'))

@section('content')
    <h1>{{ __('bank.title') }}</h1>

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif

    <p><a href="{{ route('bank.import') }}">{{ __('bank.import') }}</a></p>

    <nav class="tabs" aria-label="{{ __('bank.title') }}">
        @foreach ($views as $name)
            <a href="{{ route('bank.index', ['view' => $name]) }}" @if ($name === $view) aria-current="page" @endif>{{ __('bank.views.'.$name) }}</a>
        @endforeach
    </nav>

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
        <ul class="documents">
            @foreach ($transactions as $transaction)
                <li>
                    <a href="{{ route('bank.show', $transaction->id) }}">
                        {{ $transaction->booking_date->format('d.m.Y') }} · {{ $transaction->formattedAmount() }} · {{ $transaction->counterparty }}
                    </a>
                    <span class="muted">
                        {{ $transaction->account->name }}
                        @if ($transaction->matches_count > 0)
                            · {{ trans_choice('bank.receipts_count', $transaction->matches_count, ['count' => $transaction->matches_count]) }}
                        @endif
                    </span>
                    @if ($transaction->purpose)
                        <span class="muted statement-purpose">{{ $transaction->purpose }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
        @include('bank._pages', ['pages' => $transactions])
    @endif
@endsection
