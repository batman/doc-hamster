{{-- The import preview (A295, A302), as a window of the Bank module: shown only when something is unclear or differs.
     "Neu · Schon vorhanden · Unklar", each unclear booking decided (neu / schon vorhanden), the differing values of
     known bookings (never overwritten). --}}
@extends('layouts.app')

@section('title', __('bank.preview_title'))

@section('main_class', 'page-windows')

@section('content')
    @php
        $money = static fn (int $minor, string $currency): string => \App\Domain\Banking\Transaction::signed($minor, $currency);
        $show = static fn (string $field, mixed $value, string $currency): string => str_ends_with($field, '_minor') && is_numeric($value) ? $money((int) $value, $currency) : (string) $value;
    @endphp
    <div class="windows" data-windows>
        <x-window :title="__('bank.preview_title')" icon="file-spreadsheet" kind="bank-preview" min="28rem" optimal="40rem" max="64rem" priority="2"
                  :url="route('bank.import.preview')" :close="route('bank.index')">
            <p><strong>{{ __('bank.preview_counts', $counts) }}</strong></p>
            @if (count($prepared['groups']) > 1)
                <p class="card-note">{{ __('bank.preview_accounts') }}: {{ collect($prepared['groups'])->pluck('name')->implode(', ') }}</p>
            @endif
            @if ($errors->any())
                <p class="error" role="alert">{{ $errors->first() }}</p>
            @endif

            <form method="post" action="{{ route('bank.import.confirm') }}">
                @csrf
                @if ($unclear !== [])
                    <section class="card">
                        <h2 class="card-head"><x-icon name="alert-triangle" />{{ __('bank.unclear_title') }} <span class="pill pill-warning">{{ count($unclear) }}</span></h2>
                        <p class="card-note">{{ __('bank.unclear_hint') }}</p>
                        <ul class="pick-list">
                            @foreach ($unclear as $index => $entry)
                                @php $line = $entry['line']; $old = $existing->get($entry['existing']); $currency = $line['currency'] ?? $old?->currency ?? 'EUR'; @endphp
                                <li class="pick-row is-static">
                                    <span>
                                        <strong>{{ $line['counterparty'] ?? $line['purpose'] ?? '–' }}</strong>
                                        <small>{{ \Illuminate\Support\Carbon::parse($line['booking_date'])->format('d.m.Y') }} · {{ \Illuminate\Support\Str::limit((string) $line['purpose'], 60) }}</small>
                                        @if ($old)
                                            <small>{{ __('bank.existing_label') }}: {{ $old->counterparty ?? '–' }} · {{ \Illuminate\Support\Str::limit((string) $old->purpose, 60) }}</small>
                                        @endif
                                    </span>
                                    <span class="row-amount @if ($line['amount_minor'] > 0) is-income @endif">{{ $money($line['amount_minor'], $currency) }}</span>
                                    <span class="row-actions" role="radiogroup" aria-label="{{ __('bank.unclear_title') }}">
                                        <label class="check"><input type="radio" name="decide[{{ $index }}]" value="new" required> {{ __('bank.decide_new') }}</label>
                                        <label class="check"><input type="radio" name="decide[{{ $index }}]" value="known"> {{ __('bank.decide_known') }}</label>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($differing !== [])
                    <section class="card">
                        <h2 class="card-head"><x-icon name="alert-circle" />{{ __('bank.differing_title') }} <span class="pill pill-warning">{{ count($differing) }}</span></h2>
                        <p class="card-note">{{ __('bank.differing_hint') }}</p>
                        <ul class="prop-list">
                            @foreach ($differing as $entry)
                                @php $line = $entry['line']; $currency = $line['currency'] ?? 'EUR'; @endphp
                                @foreach ($entry['diffs'] as $field => [$before, $after])
                                    @php $label = $field === 'amount_minor' ? 'amount' : (['counterparty_iban' => 'iban', 'orig_amount_minor' => 'orig_amount', 'fee_minor' => 'fee', 'balance_minor' => 'balance', 'card_last4' => 'card'][$field] ?? $field); @endphp
                                    <li class="prop-row">
                                        <span class="prop-name">{{ \Illuminate\Support\Carbon::parse($line['booking_date'])->format('d.m.Y') }} · {{ __('bank.columns.'.$label) }}</span>
                                        <span class="prop-value">{{ $show($field, $before, $currency) }} → <span class="pill pill-warning">{{ $show($field, $after, $currency) }}</span></span>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    </section>
                @endif

                <div class="actions">
                    <a class="button button-quiet" href="{{ route('bank.index') }}">{{ __('bank.cancel') }}</a>
                    <button type="submit">{{ __('bank.preview_run') }}</button>
                </div>
            </form>
        </x-window>
    </div>
@endsection
