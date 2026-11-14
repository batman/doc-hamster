@extends('layouts.settings')

@section('title', __('exports.title'))

@section('page')
    <p>{{ __('exports.text') }}</p>

    <form method="post" action="{{ route('exports.store') }}" data-confirm-sudo>
        @csrf
        <label for="from">{{ __('exports.from') }}</label>
        <input id="from" type="date" name="from" value="{{ old('from', $from) }}" required>
        <label for="to">{{ __('exports.to') }}</label>
        <input id="to" type="date" name="to" value="{{ old('to', $to) }}" required>
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif
        <button type="submit">{{ __('exports.submit') }}</button>
    </form>
@endsection
