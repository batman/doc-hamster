{{-- Points 210.3, 215: "Passwort vergessen?" – anyone with a stored e-mail gets Laravel's reset link (only where mail works). --}}
@extends('layouts.app')

@section('title', __('auth.forgot.title'))

@section('content')
    <h1>{{ __('auth.forgot.title') }}</h1>
    <p>{{ __('auth.forgot.text') }}</p>
    <form method="post" action="{{ route('password.email') }}">
        @csrf
        {{-- PO 2026-11-16: one field and its button in one row (Feld mit Knopf). --}}
        <div class="field @error('email') has-error @enderror">
            <label for="email">{{ __('auth.forgot.email') }}</label>
            <div class="field-row">
                <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus @error('email') aria-invalid="true" @enderror>
                <button type="submit"><x-icon name="send" />{{ __('auth.forgot.send') }}</button>
            </div>
            @error('email')<p class="field-hint" role="alert">{{ $message }}</p>@enderror
        </div>
    </form>
    <p><a href="{{ route('login') }}">{{ __('auth.forgot.back') }}</a></p>
@endsection
