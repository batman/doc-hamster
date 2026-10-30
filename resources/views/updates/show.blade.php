@extends('layouts.app')

@section('title', __('updates.title'))

@section('content')
    <h1>{{ __('updates.title') }}</h1>
    <p>{{ __('updates.current', ['version' => $current]) }}</p>

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->has('password') ? __('exports.password_wrong') : $errors->first() }}</p>
    @endif

    @if ($available)
        <div class="notice">
            <p><strong>{{ __('updates.available', ['version' => $available]) }}</strong></p>
            <p>{{ __('updates.keeps_data') }}</p>
            @if ($supported)
                <form method="post" action="{{ route('updates.install') }}">
                    @csrf
                    <label for="password">{{ __('exports.password') }}</label>
                    <input id="password" type="password" name="password" autocomplete="current-password" required>
                    <button type="submit">{{ __('updates.install') }}</button>
                </form>
            @else
                <p>{{ __('updates.manual') }}</p>
            @endif
        </div>
    @else
        <p class="muted">{{ __('updates.privacy') }}</p>
        <form method="post" action="{{ route('updates.check') }}">
            @csrf
            <button type="submit">{{ __('updates.check') }}</button>
        </form>
    @endif
@endsection
