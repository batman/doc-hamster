{{-- Points 210.3, 215: the new password from the reset link. --}}
@extends('layouts.app')

@section('title', __('auth.forgot.reset_title'))

@section('content')
    <h1>{{ __('auth.forgot.reset_title') }}</h1>
    <form method="post" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="field">
            <label for="email">{{ __('auth.forgot.email') }}</label>
            <input id="email" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required>
        </div>
        <div class="field">
            <label for="password">{{ __('auth.forgot.new') }}</label>
            <input id="password" type="password" name="password" minlength="12" autocomplete="new-password" required>
            <p class="field-hint">{{ __('auth.forgot.rule') }}</p>
        </div>
        <div class="field">
            <label for="password_confirmation">{{ __('auth.forgot.repeat') }}</label>
            <input id="password_confirmation" type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required>
        </div>
        @if ($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
        <button type="submit"><x-icon name="check" />{{ __('auth.forgot.save') }}</button>
    </form>
@endsection
