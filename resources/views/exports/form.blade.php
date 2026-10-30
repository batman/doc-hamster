@extends('layouts.app')

@section('title', __('exports.title'))

@section('content')
    <h1>{{ __('exports.title') }}</h1>
    <p>{{ __('exports.text') }}</p>

    <form method="post" action="{{ route('exports.store') }}">
        @csrf
        <label for="from">{{ __('exports.from') }}</label>
        <input id="from" type="date" name="from" value="{{ old('from', $from) }}" required>
        <label for="to">{{ __('exports.to') }}</label>
        <input id="to" type="date" name="to" value="{{ old('to', $to) }}" required>
        <label for="password">{{ __('exports.password') }}</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
        @if ($errors->has('password'))
            <p class="error" role="alert">{{ __('exports.password_wrong') }}</p>
        @elseif ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif
        <button type="submit">{{ __('exports.submit') }}</button>
    </form>
@endsection
