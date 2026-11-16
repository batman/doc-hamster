@extends('setup.layout')

@section('content')
    <h1>{{ __('setup.wizard.welcome_title') }}</h1>
    <p>{{ __('setup.wizard.welcome_text') }}</p>

    @if ($codeReady)
        <p>{{ __('setup.wizard.code_where') }} <code>{{ $codePath }}</code></p>
        <form method="post" action="{{ route('setup.unlock') }}">
            @csrf
            {{-- PO 2026-11-16: the field and its button in one row (Feld mit Knopf: same height, a fixed gap). --}}
            <div class="field @error('code') has-error @enderror">
                <label for="code">{{ __('setup.wizard.code_label') }}</label>
                <div class="field-row">
                    <input id="code" name="code" autocomplete="off" required autofocus @error('code') aria-invalid="true" @enderror>
                    <button type="submit">{{ __('setup.wizard.next') }}</button>
                </div>
                @error('code') <p class="field-hint" role="alert">{{ $message }}</p> @enderror
            </div>
        </form>
    @else
        <p class="error">{{ __('setup.wizard.storage_not_writable') }}</p>
    @endif
@endsection
