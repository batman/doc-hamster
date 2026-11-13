{{-- Choosing the columns of a statement (CSV or Excel), as a window of the Bank module (A267): opened after a drop or
     an upload whose account doesn't know the columns yet. They are remembered by name, the next import asks nothing. --}}
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
                @foreach ($fields as $field)
                    @php($required = in_array($field, ['date', 'amount'], true))
                    <div class="field">
                        <label for="{{ $field }}">{{ __('bank.columns.'.$field) }}</label>
                        <select id="{{ $field }}" name="{{ $field }}" @required($required)>
                            <option value="">{{ __('bank.no_column') }}</option>
                            @foreach ($statement->header as $index => $name)
                                <option value="{{ $index }}" @selected(($columns[$field] ?? null) === $index)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <button type="submit">{{ __('bank.run') }}</button>
            </form>

            <h2>{{ __('bank.preview') }}</h2>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            @foreach ($statement->header as $name)
                                <th scope="col">{{ $name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview as $row)
                            <tr>
                                @foreach ($row as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-window>
    </div>
@endsection
