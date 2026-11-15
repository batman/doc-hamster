{{-- Points 210.3, 215: "Passwort vergessen?" – anyone with a stored e-mail gets Laravel's reset link (only where mail works). --}}
@extends('layouts.app')

@section('title', __('auth.forgot.title'))

@section('content')
    <h1>{{ __('auth.forgot.title') }}</h1>
    <p>{{ __('auth.forgot.text') }}</p>
    <form method="post" action="{{ route('password.email') }}">
        @csrf
        <div class="field">
            <label for="email">{{ __('auth.forgot.email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
        </div>
        @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
        <button type="submit"><x-icon name="send" />{{ __('auth.forgot.send') }}</button>
    </form>
    <p><a href="{{ route('login') }}">{{ __('auth.forgot.back') }}</a></p>
@endsection
