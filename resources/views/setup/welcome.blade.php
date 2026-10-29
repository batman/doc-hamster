@extends('setup.layout')

@section('content')
    <h1>{{ __('setup.wizard.welcome_title') }}</h1>
    <p>{{ __('setup.wizard.welcome_text') }}</p>

    @if ($codeReady)
        <p>{{ __('setup.wizard.code_where') }} <code>{{ $codePath }}</code></p>
        <form method="post" action="{{ route('setup.unlock') }}">
            @csrf
            <label for="code">{{ __('setup.wizard.code_label') }}</label>
            <input id="code" name="code" autocomplete="off" required autofocus>
            @error('code') <p class="error">{{ $message }}</p> @enderror
            <button type="submit">{{ __('setup.wizard.next') }}</button>
        </form>
    @else
        <p class="error">{{ __('setup.wizard.storage_not_writable') }}</p>
    @endif
@endsection
