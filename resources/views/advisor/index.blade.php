{{-- The tax advisor's read-only view (point 210.5): one window with the Umschalter Belegpakete | Buchungen | Belege in its
     toolbar; a document opens as a window to its right. Nothing here changes data; every view and download is in the
     audit trail. --}}
@extends('layouts.app')

@section('title', __('advisor.view_title'))

@section('main_class', 'page-windows')

@php
    $scope = $access === null ? __('advisor.scope_all') : implode(' · ', array_filter([
        $access->period_from === null && $access->period_to === null ? __('advisor.period_all') : ($access->period_from?->format('d.m.Y') ?? '…').' – '.($access->period_to?->format('d.m.Y') ?? '…'),
        $access->account_ids === null ? __('advisor.all_accounts') : $accounts->pluck('name')->implode(', '),
        $access->expires_at !== null ? __('advisor.until', ['date' => $access->expires_at->format('d.m.Y')]) : null,
    ]));
@endphp

@section('content')
    <div class="windows" data-windows>
    <x-window :title="__('advisor.view_title')" icon="briefcase" kind="advisor" :url="route('advisor.index', request()->query())" :close="route('advisor.index')" fit="medium" :content="$view">
        <x-slot:actions>
            <div class="switcher" role="group" aria-label="{{ __('advisor.view_title') }}" data-switch-static>
                @foreach (\App\Http\Controllers\Advisor\AdvisorController::VIEWS as $each)
                    <a href="{{ route('advisor.index', ['view' => $each]) }}" aria-pressed="{{ $view === $each ? 'true' : 'false' }}">{{ __('advisor.views.'.$each) }}</a>
                @endforeach
            </div>
        </x-slot:actions>
        <x-slot:menu>
            <a class="menu-item" href="{{ route('advisor.password') }}"><x-icon name="lock" /><span>{{ __('advisor.change_password') }}</span></a>
        </x-slot:menu>

        @if (session('status'))
            <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
        @endif
        <p class="card-note"><x-icon name="eye" /> {{ __('advisor.scope', ['scope' => $scope]) }}</p>

        @if ($view === 'packs')
            <ul class="pick-list">
                @forelse ($packs as $pack)
                    <li class="pick-row is-static">
                        <span class="row-icon"><x-icon name="file-type-pdf" /></span>
                        <span><strong>{{ $pack->fileName() }}</strong>
                            <small>{{ $pack->period()->human() }} · {{ __('receipt_pack.made', ['date' => $pack->created_at->format('d.m.Y H:i')]) }}@if ($pack->pages) · {{ trans_choice('receipt_pack.pages', $pack->pages, ['count' => $pack->pages]) }}@endif</small></span>
                        @if (($pack->summary['missing'] ?? 0) > 0)
                            <span class="pill pill-error">{{ __('receipt_pack.missing', ['count' => $pack->summary['missing']]) }}</span>
                        @endif
                        <a class="icon-button" href="{{ route('advisor.pack', $pack->id) }}" aria-label="{{ __('advisor.download') }}: {{ $pack->fileName() }}" data-tooltip="{{ __('advisor.download') }}"><x-icon name="download" /></a>
                    </li>
                @empty
                    <x-empty tag="li" icon="file-type-pdf" :text="__('advisor.no_packs')" />
                @endforelse
            </ul>
        @elseif ($view === 'bookings')
            @if ($accounts->count() > 1)
                <span class="switcher" role="group" aria-label="{{ __('advisor.accounts') }}">
                    <a href="{{ route('advisor.index', ['view' => 'bookings']) }}" aria-pressed="{{ $account === null ? 'true' : 'false' }}">{{ __('advisor.all') }}</a>
                    @foreach ($accounts as $each)
                        <a href="{{ route('advisor.index', ['view' => 'bookings', 'account' => $each->id]) }}" aria-pressed="{{ $account === $each->id ? 'true' : 'false' }}">{{ $each->name }}</a>
                    @endforeach
                </span>
            @endif
            <ul class="pick-list">
                @forelse ($bookings ?? [] as $booking)
                    <li class="pick-row is-static">
                        <span class="row-icon"><x-icon :name="$booking->account->icon()" /></span>
                        <span><strong>{{ $booking->counterparty ?: ($booking->purpose ?: '–') }}</strong>
                            <small>{{ $booking->bookedOn() }} · {{ $booking->account->name }}@if ($booking->purpose && $booking->counterparty) · {{ \Illuminate\Support\Str::limit($booking->purpose, 80) }}@endif</small></span>
                        <span class="pill">{{ $booking->signedAmount() }}</span>
                        @forelse ($booking->matches as $match)
                            @if ($match->document)
                                <a class="icon-button" href="{{ route('advisor.document', $match->document->id) }}" data-window aria-label="{{ __('advisor.open') }}: {{ $match->document->title }}" data-tooltip="{{ $match->document->title }}"><x-icon name="receipt" /></a>
                            @endif
                        @empty
                            @if ($booking->no_receipt)
                                <span class="pill">{{ __('advisor.none_needed') }}</span>
                            @else
                                <span class="pill pill-error">{{ __('advisor.missing') }}</span>
                            @endif
                        @endforelse
                    </li>
                @empty
                    <x-empty tag="li" icon="building-bank" :text="__('advisor.no_bookings')" />
                @endforelse
            </ul>
            @if ($bookings !== null)
                @include('bank._pages', ['pages' => $bookings])
            @endif
        @else
            <ul class="pick-list">
                @forelse ($documents ?? [] as $document)
                    <li class="pick-row is-static">
                        <span class="row-icon"><x-icon :name="$document->files->first()?->typeIcon() ?? 'file-text'" /></span>
                        <span><strong><a href="{{ route('advisor.document', $document->id) }}" data-window>{{ $document->title }}</a></strong>
                            <small>{{ $document->dateBy('doc')->format('d.m.Y') }}</small></span>
                        @if ($file = $document->files->first())
                            <a class="icon-button" href="{{ route('advisor.file', [$file->id, 'download' => 1]) }}" aria-label="{{ __('advisor.download') }}: {{ $document->title }}" data-tooltip="{{ __('advisor.download') }}"><x-icon name="download" /></a>
                        @endif
                    </li>
                @empty
                    <x-empty tag="li" icon="receipt" :text="__('advisor.no_documents')" />
                @endforelse
            </ul>
            @if ($documents !== null)
                @include('bank._pages', ['pages' => $documents])
            @endif
        @endif
    </x-window>
    </div>
@endsection
