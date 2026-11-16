{{-- Einstellungen → Mein Konto (point 222): the person's own name (click to edit, saves at once), password and the
     card "Angemeldete Geräte" and the Zugangsschlüssel for the interface (D-040). After a sign-in with a one-time password ($forced) the password card opens in that mode. --}}
@extends('layouts.settings')

@section('title', __('account.title'))

@php
    $passwordErrors = $errors->has('current') || $errors->has('password');
    $passwordOpen = $forced || $passwordErrors;
    $hasPassword = $user->hasPassword();
@endphp

@section('page')
    @if (session('status'))
        <div class="toast" role="status" data-toast><x-icon name="circle-check" /><span>{{ session('status') }}</span></div>
    @endif

    <section class="card">
        <h2 class="card-head"><x-icon name="user" />{{ __('account.name_title') }}</h2>
        <x-edit-in-place name="display_name" :value="$user->display_name" :action="route('account.name')" :label="__('account.name_label')" maxlength="100" />
        <p class="card-note">{{ __('account.name_note') }}@if ($user->username !== null) · {{ __('account.username', ['name' => $user->username]) }}@endif</p>
    </section>

    <x-fold-card id="account-password" fold="account.password" :title="$hasPassword ? __('account.password_title') : __('account.password_first_title')" icon="lock"
        :folded="! $passwordOpen" :remember="! $passwordOpen">
        <p class="card-text">@if ($forced)<strong>{{ __('account.password_forced') }}</strong> @endif{{ __('account.password_text') }}</p>
        <form method="post" action="{{ route('account.password.update') }}">
            @csrf
            @if ($hasPassword)
                <div class="field">
                    <label for="account-current">{{ $forced ? __('account.current_onetime') : __('account.current') }}</label>
                    <input id="account-current" type="password" name="current" autocomplete="current-password" required @if ($forced) autofocus @endif>
                    @if ($forced)<p class="field-hint">{{ __('account.current_onetime_hint') }}</p>@endif
                    @error('current')<p class="error" role="alert">{{ $message }}</p>@enderror
                </div>
            @endif
            <div class="field">
                <label for="account-new">{{ __('account.new') }}</label>
                <input id="account-new" type="password" name="password" minlength="12" maxlength="200" autocomplete="new-password" required>
                <p class="field-hint">{{ __('account.rule') }}</p>
                @error('password')<p class="error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="account-new-confirmation">{{ __('account.repeat') }}</label>
                <input id="account-new-confirmation" type="password" name="password_confirmation" minlength="12" maxlength="200" autocomplete="new-password" required>
            </div>
            <button type="submit"><x-icon name="check" />{{ __('account.save') }}</button>
        </form>
    </x-fold-card>

    @include('settings._sessions')

    @include('settings._access-keys')
@endsection
