@extends('layouts.app')

@section('title', __('bank.columns_title'))

@section('content')
    <h1>{{ __('bank.columns_title') }}</h1>
    <p>{{ __('bank.columns_hint', ['account' => $accountName]) }}</p>

    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <form method="post" action="{{ route('bank.import.run') }}">
        @csrf
        @foreach ($fields as $field)
            @php($required = in_array($field, ['date', 'amount'], true))
            <label for="{{ $field }}">{{ __('bank.columns.'.$field) }}</label>
            <select id="{{ $field }}" name="{{ $field }}" @required($required)>
                <option value="">{{ __('bank.no_column') }}</option>
                @foreach ($statement->header as $index => $name)
                    <option value="{{ $index }}" @selected($columns[$field] === $index)>{{ $name }}</option>
                @endforeach
            </select>
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
@endsection
