@extends('layouts.settings')

@section('title', __('scan.settings_title'))

@section('page')
    <h1>{{ __('scan.settings_title') }}</h1>

    @if (session('status'))
        <p class="check-ok" role="status">{{ session('status') }}</p>
    @endif
    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <p>{{ __('scan.settings_text') }}</p>
    @if ($name)
        <p class="check-ok">{{ __('scan.connected', ['name' => $name]) }}</p>
    @endif
    <form method="post" action="{{ route('scanner.save') }}">
        @csrf
        <label for="url">{{ __('scan.address') }}</label>
        <input id="url" name="url" value="{{ old('url', $url) }}" maxlength="200" placeholder="192.168.0.20" autocomplete="off" inputmode="url">
        <p class="muted">{{ __('scan.address_hint') }}</p>
        <div class="actions"><button type="submit">{{ __('scan.save') }}</button></div>
    </form>
@endsection
