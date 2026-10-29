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
@endsection
