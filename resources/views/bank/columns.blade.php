{{-- Choosing the columns of a statement (CSV or Excel), as a window of the Bank module (A267, A301): each column with
     three sample values and the proposed field – recognised by its name or remembered (green), guessed from its content
     (orange). Only date and amount are required; a column without a field is kept as "weitere Angaben" (A290). The
     choice is remembered by column name, the next import of the account asks nothing. --}}
@extends('layouts.app')

@section('title', __('bank.columns_title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
        <x-window :title="__('bank.columns_title')" icon="file-spreadsheet" kind="bank-columns" min="28rem" optimal="40rem" max="64rem" priority="2"
                  :url="route('bank.import.columns')" :close="route('bank.index')">
            <p>{{ __('bank.columns_hint', ['account' => $accountName]) }}</p>

            @if ($errors->any())
                <p class="error" role="alert">{{ $errors->first() }}</p>
            @endif

            <form method="post" action="{{ route('bank.import.run') }}">
                @csrf
                @if ($account === null)
                    <div class="field">
                        <label for="name">{{ __('bank.account_name') }}</label>
                        <input id="name" name="name" value="{{ old('name', $accountName) }}" maxlength="150" required>
                    </div>
                @endif
                <ul class="pick-list">
                    @foreach ($statement->header as $index => $name)
                        @php $field = $byColumn[$index] ?? null; @endphp
                        <li class="pick-row is-static">
                            <span class="row-icon"><x-icon name="file-spreadsheet" /></span>
                            <span>
                                <strong>{{ $name }}</strong>
                                <small>{{ implode(' · ', $samples[$index]) ?: '–' }}</small>
                            </span>
                            @if ($field !== null)
                                <span class="pill {{ ($sure[$field] ?? false) ? 'pill-ok' : 'pill-warning' }}">{{ ($sure[$field] ?? false) ? __('bank.column_sure') : __('bank.column_unsure') }}</span>
                            @endif
                            <select class="input-small" name="map[{{ $index }}]" aria-label="{{ __('bank.columns_field') }}: {{ $name }}">
                                <option value="">{{ __('bank.column_extra') }}</option>
                                @foreach ($fields as $option)
                                    <option value="{{ $option }}" @selected(old('map.'.$index, $field) === $option)>{{ __('bank.columns.'.$option) }}@if (in_array($option, \App\Domain\Banking\StatementColumns::REQUIRED, true)) *@endif</option>
                                @endforeach
                            </select>
                        </li>
                    @endforeach
                </ul>
                <div class="actions">
                    <button type="submit">{{ __('bank.run') }}</button>
                </div>
            </form>
        </x-window>
    </div>
@endsection
