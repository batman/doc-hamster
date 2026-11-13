{{-- The Bank module (points 129, A267–A274), a row of windows like Dokumente: the Bank window lists the accounts, an
     account opens as a window with its bookings, a booking as a window with its documents. A CSV/Excel statement dropped
     onto the Bank window, an account row or an account window is imported (modules/bank.js, data-statement-drop). --}}
@extends('layouts.app')

@section('title', $transaction?->formattedAmount() ?? $account?->name ?? __('bank.title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
        <x-window :title="__('bank.title')" icon="building-bank" kind="bank" :url="route('bank.index')" :close="route('home')"
                  data-statement-drop="{{ route('bank.import.upload') }}" data-drop-label="{{ __('bank.drop_here') }}">
            <x-slot:menu>
                <a class="menu-item" href="{{ route('bank.import') }}"><x-icon name="settings" /><span>{{ __('bank.manage') }}</span></a>
            </x-slot:menu>
            @if (session('status') && ! $account)
                <p class="check-ok" role="status">{{ session('status') }}</p>
            @endif
            <ul class="pick-list">
                @forelse ($accounts as $entry)
                    @php $open = (int) $entry->open_count; $last = $entry->transactions_max_created_at; @endphp
                    <li class="pick-row is-static @if ($account?->id === $entry->id) is-marked @endif" data-statement-drop="{{ route('bank.import.upload') }}" data-account="{{ $entry->id }}"
                        data-upload-label="{{ __('bank.drop_into', ['name' => $entry->name]) }}">
                        <span class="row-icon"><x-icon name="building-bank" /></span>
                        <span>
                            <strong><a href="{{ route('bank.account', $open > 0 ? [$entry->id, 'status' => 'open'] : [$entry->id]) }}" data-window>{{ $entry->name }}</a></strong>
                            <small>
                                @if ($entry->iban){{ trim(chunk_split($entry->iban, 4, ' ')) }} · @endif
                                @if ($last){{ __('bank.last_import', ['date' => \Illuminate\Support\Carbon::parse($last)->format('d.m.Y')]) }} · @endif
                                {{ trans_choice('bank.transactions_count', $entry->transactions_count, ['count' => $entry->transactions_count]) }}
                            </small>
                        </span>
                        {{-- A274: the menu's count leads here, then to the account's open bookings. --}}
                        @if ($open > 0)
                            <span class="badge badge-alert" aria-label="{{ trans_choice('bank.open_count', $open, ['count' => $open]) }}">{{ $open > 99 ? '99+' : $open }}</span>
                        @endif
                    </li>
                @empty
                    <li class="list-empty">{{ __('bank.accounts_empty') }}</li>
                @endforelse
                <li class="pick-row is-static @if ($receipts) is-marked @endif">
                    <span class="row-icon"><x-icon name="file-off" /></span>
                    <span>
                        <strong><a href="{{ route('bank.receipts') }}" data-window>{{ __('bank.free_receipts') }}</a></strong>
                        <small>{{ trans_choice('bank.free_receipts_count', $freeReceipts, ['count' => $freeReceipts]) }}</small>
                    </span>
                </li>
            </ul>
            <p class="card-note">{{ __('bank.drop_hint') }}</p>
            <div hidden data-bank-texts data-drop-one="{{ __('bank.drop_one') }}" data-importing="{{ __('bank.importing') }}"></div>
        </x-window>

        @if ($account)
            @include('bank._account')
        @endif

        @if ($receipts)
            @include('bank._receipts')
        @endif

        @if ($transaction)
            @include('bank._transaction')
        @endif
    </div>
@endsection
