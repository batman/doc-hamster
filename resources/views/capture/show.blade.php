@extends('layouts.app')

@section('title', __('capture.title'))

@push('head')
    <script src="{{ asset('js/capture.js') }}" defer></script>
@endpush

@section('content')
    <h1>{{ __('capture.title') }}</h1>
    <p>{{ __('capture.intro') }}</p>

    <div id="capture"
        data-hint="{{ __('capture.hint') }}"
        data-no-camera="{{ __('capture.no_camera') }}"
        data-denied="{{ __('capture.denied') }}"
        data-failed="{{ __('capture.failed') }}"
        data-sending="{{ __('capture.sending') }}">
        {{-- Live camera; the script unhides it, so without script only the file form below shows. --}}
        <div data-live hidden>
            <video id="capture-preview" playsinline muted hidden></video>
            <canvas id="capture-canvas" hidden></canvas>
            <p>
                <button type="button" data-start-camera>{{ __('capture.open_camera') }}</button>
                <button type="button" data-take-photo hidden>{{ __('capture.take_photo') }}</button>
            </p>
        </div>
        <p data-status role="status"></p>

        <form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" data-capture-form>
            @csrf
            <label class="file-button">
                {{ __('capture.file_label') }}
                <input type="file" name="file" accept="image/*" capture="environment" data-capture>
            </label>
            <p><button type="submit">{{ __('capture.send') }}</button></p>
        </form>
        <p><small class="muted">{{ __('capture.privacy') }}</small></p>
    </div>
@endsection
