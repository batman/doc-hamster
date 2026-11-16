@extends('layouts.app')

@section('title', __('auth.login'))

@section('content')
    <h1>{{ __('auth.login') }}</h1>
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif
    <form method="post" action="{{ route('login.attempt') }}">
        @csrf
        <div class="field">
            <label for="username">{{ __('auth.username') }}</label>
            <input id="username" name="username" value="{{ old('username') }}" autocomplete="username" required autofocus @error('username') aria-invalid="true" @enderror>
        </div>
        <div class="field">
            <label for="password">{{ __('auth.password') }}</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
        </div>
        <label><input type="checkbox" name="remember" value="1" checked> {{ __('auth.remember') }}</label>
        @error('username')<p class="error" role="alert">{{ $message }}</p>@enderror
        <button type="submit">{{ __('auth.login') }}</button>
    </form>
    @if ($forgot ?? false)
        {{-- Points 210.3, 215: for everyone with a stored e-mail (only where mail works). --}}
        <p><a href="{{ route('password.request') }}">{{ __('auth.forgot.link') }}</a></p>
    @endif
@endsection
