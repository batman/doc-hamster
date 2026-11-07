@extends('layouts.app')

@section('title', __('phone.connect_title'))

@section('content')
    <h1>{{ __('phone.connect_title') }}</h1>
    <p>{{ __('phone.connect_text') }}</p>
    <form method="post" action="{{ route('phone.connect.save') }}">
        @csrf
        <label>{{ __('phone.code') }}
            <input name="code" value="{{ old('code', $code) }}" autocomplete="one-time-code" autocapitalize="characters" maxlength="20" required @if ($code === '') autofocus @endif>
        </label>
        @error('code')<p class="error" role="alert">{{ $message }}</p>@enderror
        <button type="submit">{{ __('phone.connect') }}</button>
    </form>
@endsection
