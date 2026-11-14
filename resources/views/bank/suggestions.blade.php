{{-- "Belegvorschläge" (point 193, A367): a window beside the booking window with at most ten documents, best score first,
     each with its percentage, none under 20 %; the toolbar search looks through all documents instead. A row drags onto
     the booking (no grip, point 192) or links with its link button; modules/bank.js reloads the Bank windows. --}}
@extends('layouts.app')

@section('title', __('bank.suggestions'))

@section('main_class', 'page-windows')

@section('content')
    @php
        $money = static fn (int $minor): string => \App\Domain\Banking\Transaction::money($minor, $transaction->currency);
        $why = static fn (array $reasons): string => __('bank.why', ['reasons' => implode(', ', array_map(static fn (string $key): string => __('bank.reasons.'.$key), array_keys($reasons)))]);
    @endphp
    <div class="windows" data-windows>
        <x-window :title="__('bank.suggestions')" icon="file-invoice" kind="bank-suggestions" min="22rem" optimal="30rem" max="44rem" priority="1"
                  :url="route('bank.suggestions', array_filter([$transaction->id, 'q' => $search !== '' ? $search : null, 'linked' => $withLinked ? 1 : null]))"
                  :close="route('bank.show', $transaction->id)" :search="__('bank.suggestions_search')">
            <p class="card-note">{{ __('bank.suggestions_hint') }}</p>
            {{-- A318: documents with a booking already stay out unless asked for. --}}
            <div class="bank-ticks">
                <label class="check"><input type="checkbox" data-bank-reload="{{ route('bank.suggestions', array_filter([$transaction->id, 'q' => $search !== '' ? $search : null, 'linked' => $withLinked ? null : 1])) }}" @checked($withLinked)> {{ __('bank.show_linked') }}</label>
            </div>
            <ul class="pick-list">
                @forelse ($suggestions as $suggestion)
                    @php $document = $suggestion['document']; $amount = $amounts[$document->id] ?? null; $other = $elsewhere[$document->id] ?? null; @endphp
                    <li class="pick-row is-static drag-row" data-doc-drag="{{ $document->id }}" data-doc-file="{{ $document->dragFile() }}">
                        <span class="row-icon"><x-icon name="file-text" /></span>
                        <span>
                            <strong><a href="{{ route('documents.show', $document->id) }}" data-window><span data-doc-title>{{ $document->title }}</span></a></strong>
                            <small>
                                {{ $document->dateBy('doc')->format('d.m.Y') }}
                                @if ($amount !== null) · {{ $money(abs($amount)) }}@endif
                                @if ($suggestion['reasons'] !== []) · {{ $why($suggestion['reasons']) }}@endif
                                @if ($other)
                                    <br>{{ __('bank.linked_with', ['names' => $other['names']]) }}@if ($amount !== null) · {{ __('bank.remaining', ['amount' => $money(abs($amount) - $other['sum'])]) }}@endif
                                @endif
                            </small>
                        </span>
                        <span class="pill @if ($suggestion['score'] >= \App\Domain\Banking\MatchScore::SUGGEST) pill-new @endif">{{ __('bank.score', ['score' => $suggestion['score']]) }}</span>
                        <span class="row-actions">
                            <form method="post" action="{{ route('bank.match', $transaction->id) }}" data-bank-action data-bank-match>
                                @csrf
                                <input type="hidden" name="document_id" value="{{ $document->id }}">
                                <button type="submit" class="control-button" aria-label="{{ __('bank.link_here') }}: {{ $document->title }}" data-tooltip="{{ __('bank.link_here') }}"><x-icon name="link" /></button>
                            </form>
                        </span>
                    </li>
                @empty
                    <x-empty tag="li" icon="search" :text="$search !== '' ? __('bank.no_search_results') : __('bank.no_suggestions')" />
                @endforelse
            </ul>
            @if ($suggestions === [] && $search === '')
                <p class="card-note">{{ __('bank.no_suggestions_hint') }}</p>
            @endif
        </x-window>
    </div>
@endsection
