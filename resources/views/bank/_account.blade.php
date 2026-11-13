{{-- One account as a window (A267): its bookings newest first, search in counterparty and purpose, status filter
     (A270) in the search panel. A statement dropped onto the window imports into this account; a document dragged onto a
     booking row links it (A269, data-doc-drop, the drag engine in modules/documents.js). --}}
@php
    $statusClass = ['open' => 'pill-warning', 'suggested' => 'pill-new', 'linked' => 'pill-ok', 'none' => ''];
    $accountUrl = route('bank.account', array_filter([$account->id, 'status' => $status, 'q' => $accountSearch !== '' ? $accountSearch : null]));
@endphp
<x-window :title="$account->name" icon="building-bank" kind="bank-account" min="24rem" optimal="34rem" max="52rem"
          :url="$accountUrl" :close="route('bank.index')"
          :search="__('bank.search')" search-params="status" :search-count="$status ? 1 : 0"
          data-statement-drop="{{ route('bank.import.upload') }}" data-account="{{ $account->id }}" data-drop-label="{{ __('bank.drop_into', ['name' => $account->name]) }}">
    <x-slot:searchOptions>
        <fieldset class="search-group">
            <legend>{{ __('bank.status_filter') }}</legend>
            <label class="check"><input type="radio" name="status" value="" data-default @checked($status === null)> {{ __('bank.all') }}</label>
            @foreach (\App\Domain\Banking\Transaction::STATUSES as $name)
                <label class="check"><input type="radio" name="status" value="{{ $name }}" @checked($status === $name)> {{ __('bank.status.'.$name) }} <span class="pill">{{ $statusCounts[$name] }}</span></label>
            @endforeach
        </fieldset>
    </x-slot:searchOptions>
    @if (session('status') && ! $transaction)
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    <ul class="pick-list">
        @forelse ($transactions as $entry)
            @php $state = $entry->status(); @endphp
            <li class="pick-row is-static @if ($transaction?->id === $entry->id) is-marked @endif" data-doc-drop="{{ route('bank.link', $entry->id) }}">
                <span class="row-icon"><x-icon :name="$entry->amount_minor < 0 ? 'arrow-up-right' : 'arrow-down-left'" /></span>
                <span>
                    <strong><a href="{{ route('bank.show', $entry->id) }}" data-window>{{ $entry->counterparty ?? $entry->purpose ?? '–' }}</a></strong>
                    <small>{{ $entry->booking_date->format('d.m.Y') }}@if ($entry->purpose && $entry->counterparty) · {{ \Illuminate\Support\Str::limit($entry->purpose, 80) }}@endif</small>
                </span>
                <span class="pill {{ $statusClass[$state] }}">{{ __('bank.status.'.$state) }}</span>
                <span class="row-amount @if ($entry->amount_minor > 0) is-income @endif">{{ $entry->formattedAmount() }}</span>
            </li>
        @empty
            <li class="list-empty">{{ $status || $accountSearch !== '' ? __('bank.empty_filtered') : __('bank.empty') }}</li>
        @endforelse
    </ul>
    @include('bank._pages', ['pages' => $transactions])
    <div hidden data-bank-texts data-drop-one="{{ __('bank.drop_one') }}" data-importing="{{ __('bank.importing') }}"></div>
</x-window>
