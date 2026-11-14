{{-- Point 210.2: the tax advisor sets his own password – first after a one-time password (nothing else opens before),
     later whenever he likes. --}}
@extends('layouts.app')

@section('title', __('advisor.password_set_title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    <x-window :title="__('advisor.password_set_title')" icon="lock" kind="advisor-password" :url="route('advisor.password')" :close="route('advisor.index')" fit="narrow">
        <p class="card-text">{{ $forced ? __('advisor.password_forced') : __('advisor.password_change_text') }}</p>
        <form method="post" action="{{ route('advisor.password.update') }}">
            @csrf
            <div class="field">
                <label for="advisor-current">{{ $forced ? __('advisor.password_current_onetime') : __('advisor.password_current') }}</label>
                <input id="advisor-current" type="password" name="current" autocomplete="current-password" required>
                @error('current')<p class="error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="advisor-new">{{ __('advisor.password_new') }}</label>
                <input id="advisor-new" type="password" name="password" minlength="12" autocomplete="new-password" required>
                <p class="field-hint">{{ __('advisor.password_rule') }}</p>
                @error('password')<p class="error" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="advisor-new-confirmation">{{ __('advisor.password_repeat') }}</label>
                <input id="advisor-new-confirmation" type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required>
            </div>
            <button type="submit"><x-icon name="check" />{{ __('advisor.password_save') }}</button>
        </form>
    </x-window>
    </div>
@endsection
