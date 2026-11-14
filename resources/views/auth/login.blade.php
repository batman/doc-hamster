@extends('layouts.app')

@section('title', __('auth.login'))

@section('content')
    <h1>{{ __('auth.login') }}</h1>
    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
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
        <label class="chip"><input type="checkbox" name="remember" value="1" checked> <span>{{ __('auth.remember') }}</span></label>
        @error('username')<p class="error" role="alert">{{ $message }}</p>@enderror
        <button type="submit">{{ __('auth.login') }}</button>
    </form>
    @if ($forgot ?? false)
        {{-- Point 210.3: for tax advisors with a stored e-mail (only where mail works). --}}
        <p><a href="{{ route('password.request') }}">{{ __('advisor.forgot_link') }}</a></p>
    @endif
@endsection
