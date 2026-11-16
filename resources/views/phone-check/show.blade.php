@extends('layouts.app')

@section('title', __('phone_check.title'))

@push('head')
    <link rel="manifest" href="{{ route('phone-check.manifest') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    <script src="{{ asset('js/vendor/jsqr.js') }}" defer></script>
    <script src="{{ asset('js/phone-check.js') }}" defer></script>
@endpush

@section('content')
    <h1>{{ __('phone_check.title') }}</h1>
    <p>{{ __('phone_check.intro') }}</p>

    <ol class="phone-checks" id="phone-checks"
        data-ok="{{ __('phone_check.ok') }}"
        data-warn="{{ __('phone_check.warn') }}"
        data-fail="{{ __('phone_check.fail') }}"
        data-key-new="{{ __('phone_check.key_new') }}"
        data-key-found="{{ __('phone_check.key_found') }}"
        data-other-code="{{ __('phone_check.other_code') }}">
        @foreach (['standalone', 'key', 'storage', 'camera', 'qr', 'photo', 'file'] as $check)
            <li data-check="{{ $check }}">
                {{ __('phone_check.check_'.$check) }}:
                <strong class="check-state">{{ __('phone_check.open') }}</strong>
                <small class="check-detail muted"></small>
            </li>
        @endforeach
    </ol>

    <button type="button" id="start-camera">{{ __('phone_check.start_camera') }}</button>
    <video id="camera" playsinline muted hidden></video>

    {{-- The framework's file buttons (PO 2026-11-16). --}}
    <div class="actions">
        <label class="button button-quiet file-button"><input class="visually-hidden" type="file" id="photo-input" accept="image/*" capture="environment"><x-icon name="photo" />{{ __('phone_check.take_photo') }}</label>
        <label class="button button-quiet file-button"><input class="visually-hidden" type="file" id="file-input" accept="image/*,application/pdf"><x-icon name="upload" />{{ __('phone_check.pick_file') }}</label>
    </div>

    <p><small class="muted" id="phone-check-device"></small></p>
@endsection
