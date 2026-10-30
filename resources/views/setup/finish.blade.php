@extends('setup.layout')

@section('content')
    <h1>{{ __('setup.wizard.finish_title') }}</h1>
    <p>{{ __('setup.wizard.finish_text') }}</p>
    <h2>{{ __('setup.wizard.cron_title') }}</h2>
    <p>{{ __('setup.wizard.cron_text') }}</p>
    <p><code>{{ $cronCommand }}</code></p>
    <form method="post" action="{{ route('setup.finish.save') }}">
        @csrf
        @if ($needsOwner)
            <p>{{ __('setup.wizard.owner_text') }}</p>
            <label for="org">{{ __('auth.owner.org') }}</label>
            <input id="org" name="org" value="{{ old('org') }}" required>
            <label for="username">{{ __('auth.username') }}</label>
            <input id="username" name="username" value="{{ old('username') }}" autocomplete="username" required>
            <label for="password">{{ __('auth.password') }}</label>
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
            <label for="password_confirmation">{{ __('setup.wizard.password_repeat') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            @if ($errors->any()) <p class="error">{{ __('auth.owner.invalid') }}</p> @endif
        @endif
        <button type="submit">{{ __('setup.wizard.finish_submit') }}</button>
    </form>
@endsection
