@extends('layouts.app')

@section('title', __('auth.login'))

@section('content')
    <h1>{{ __('auth.login') }}</h1>
    <form method="post" action="{{ route('login.attempt') }}">
        @csrf
        <label>{{ __('auth.username') }}
            <input name="username" value="{{ old('username') }}" autocomplete="username" required autofocus>
        </label>
        <label>{{ __('auth.password') }}
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        @error('username')<p class="error" role="alert">{{ $message }}</p>@enderror
        <button type="submit">{{ __('auth.login') }}</button>
    </form>
    {{-- A10: the phone signs in with the QR code from the computer, no password (PHONE, issue #37). --}}
    <div class="login-phone">
        <a class="button-quiet" href="{{ route('phone.connect') }}"><x-icon name="device-mobile" /> {{ __('phone.login_button') }}</a>
        <p class="muted">{{ __('phone.login_hint') }}</p>
    </div>
@endsection
