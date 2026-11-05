@extends('layouts.settings')

@section('title', __('settings.title'))

{{-- The language form lives on the landing page only; group pages share this
     view, and an empty section keeps their columns unchanged. --}}
@section('page')
    @if (request()->routeIs('settings.index'))
        <h1>{{ __('settings.language.title') }}</h1>
        @if (session('status'))
            <p class="check-ok" role="status">{{ session('status') }}</p>
        @endif
        <form method="post" action="{{ route('settings.language') }}">
            @csrf
            <label for="locale">{{ __('settings.language.label') }}</label>
            <select id="locale" name="locale" required>
                <option value="de" @selected(old('locale', app()->getLocale()) === 'de')>Deutsch</option>
                <option value="en" @selected(old('locale', app()->getLocale()) === 'en')>English</option>
            </select>
            @error('locale')<p class="error" role="alert">{{ $message }}</p>@enderror
            <button type="submit">{{ __('settings.language.save') }}</button>
        </form>
    @endif
@endsection
