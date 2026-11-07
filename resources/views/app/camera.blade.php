{{-- Belege: one tap, the photo goes to DocHamster (into "Belege"); without a connection it waits on the
     phone (app.js, queue). Android and others: the live camera is open at once. iPhone: the shutter opens
     the phone's own camera, because home-screen apps there would ask for the camera on every start. --}}
@extends('layouts.phone')

@section('title', __('app.bar.receipts'))
@section('body_class', 'phone-camera')

@section('content')
    <div class="camera" data-live-camera>
        <video class="camera-view" playsinline muted autoplay disablepictureinpicture data-camera-view></video>
        <div class="camera-ready" data-camera-ready hidden>
            <span class="camera-ready-hamster" aria-hidden="true" data-hamster="awake"></span>
            <p>{{ __('app.camera.tap') }}</p>
        </div>
        <p class="camera-hint" data-camera-hint hidden>{{ __('app.camera.no_camera') }}</p>
        <p class="camera-allow" data-camera-allow hidden>{{ __('app.camera.allow') }}</p>
        <div class="camera-flash" data-camera-flash hidden></div>
        @if ($offline ?? false)
            <p class="camera-offline" role="status"><strong>{{ __('app.shell.offline_title') }}</strong> {{ __('app.camera.offline') }}</p>
        @endif
    </div>
    <form class="camera-controls" method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" data-queue-upload data-camera-form>
        @unless ($offline ?? false)
            @csrf
            <input type="hidden" name="app_back" value="{{ route('app.home', absolute: false) }}">
        @endunless
        <label class="camera-pick" aria-label="{{ __('app.camera.pick') }}">
            <input class="visually-hidden" type="file" name="files[]" accept="image/*,application/pdf" multiple>
            <x-icon name="photo" />
        </label>
        <button type="button" class="shutter" data-shutter aria-label="{{ __('app.camera.shoot') }}"></button>
        {{-- The phone's own camera: opened by the shutter when there is no live camera. --}}
        <input class="visually-hidden" type="file" name="files[]" accept="image/*" capture="environment" tabindex="-1" aria-hidden="true" data-camera-native>
        <span class="camera-spacer" aria-hidden="true"></span>
        <noscript><button type="submit">{{ __('documents.upload') }}</button></noscript>
    </form>
@endsection
