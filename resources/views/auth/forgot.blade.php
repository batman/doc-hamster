{{-- Point 210.3: "Passwort vergessen?" – tax advisors with a stored e-mail get Laravel's reset link (only where mail works). --}}
@extends('layouts.app')

@section('title', __('advisor.forgot_title'))

@section('content')
    <h1>{{ __('advisor.forgot_title') }}</h1>
    <p>{{ __('advisor.forgot_text') }}</p>
    <form method="post" action="{{ route('password.email') }}">
        @csrf
        <div class="field">
            <label for="email">{{ __('advisor.email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
        </div>
        @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
        <button type="submit"><x-icon name="send" />{{ __('advisor.forgot_send') }}</button>
    </form>
    <p><a href="{{ route('login') }}">{{ __('advisor.back_to_login') }}</a></p>
@endsection
