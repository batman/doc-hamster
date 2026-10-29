@extends('setup.layout')

@section('content')
    <h1>{{ __('setup.wizard.db_title') }}</h1>

    @if ($ready)
        <p>{{ __('setup.wizard.db_ready') }}</p>
        <a class="button" href="{{ route('setup.finish') }}">{{ __('setup.wizard.next') }}</a>
    @else
        <p>{{ __('setup.wizard.db_text') }}</p>
        <form method="post" action="{{ route('setup.database.save') }}">
            @csrf
            <label for="host">{{ __('setup.wizard.db_host') }}</label>
            <input id="host" name="host" value="{{ old('host', 'localhost') }}" required>
            <label for="port">{{ __('setup.wizard.db_port') }}</label>
            <input id="port" name="port" inputmode="numeric" value="{{ old('port', '3306') }}" required>
            <label for="database">{{ __('setup.wizard.db_name') }}</label>
            <input id="database" name="database" value="{{ old('database', 'dochamster') }}" required>
            <label for="username">{{ __('setup.wizard.db_user') }}</label>
            <input id="username" name="username" value="{{ old('username') }}" autocomplete="off" required>
            <label for="password">{{ __('setup.wizard.db_password') }}</label>
            <input id="password" name="password" type="password" autocomplete="new-password">
            @error('database') <p class="error">{{ $message }}</p> @enderror
            @if ($errors->any() && ! $errors->has('database')) <p class="error">{{ __('setup.wizard.db_check_fields') }}</p> @endif
            <button type="submit">{{ __('setup.wizard.db_submit') }}</button>
        </form>
    @endif
@endsection
