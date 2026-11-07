{{-- Belege: the camera is open at once. One tap, the photo goes to DocHamster (into "Belege"); without a
     connection it waits on the phone (app.js, queue). No permission or no camera: pick a photo instead. --}}
@extends('layouts.phone')

@section('title', __('app.bar.receipts'))
@section('body_class', 'phone-camera')

@section('content')
    <div class="camera" data-live-camera>
        <video class="camera-view" playsinline muted autoplay data-camera-view></video>
        <p class="camera-hint" data-camera-hint hidden>{{ __('app.camera.no_camera') }}</p>
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
        <label class="camera-native" aria-label="{{ __('app.camera.native') }}">
            <input class="visually-hidden" type="file" name="files[]" accept="image/*" capture="environment">
            <x-icon name="camera" />
        </label>
        <noscript><button type="submit">{{ __('documents.upload') }}</button></noscript>
    </form>
@endsection
