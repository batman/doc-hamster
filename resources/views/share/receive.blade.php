{{-- "Teilen → DocHamster" (points 151, 151.3): the service worker kept the shared files on the phone and sent the phone here
     (after login, if needed). modules/share.js sends them now and goes on to the confirmation; on failure they stay kept. --}}
@extends('layouts.app')

@section('title', __('share.receive_title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    <x-window :title="__('share.receive_title')" icon="upload" kind="share" :url="route('share.receive')" :close="route('home')">
        <div data-share-replay="{{ route('share.store') }}" data-home="{{ route('home') }}" data-nothing="{{ __('share.nothing') }}" data-failed="{{ __('share.failed') }}" data-retry="{{ __('share.retry') }}" data-finish="{{ __('share.finish') }}">
            <p class="card-text" data-share-message>{{ __('share.saving') }}</p>
            <button type="button" class="is-busy" disabled data-share-button><x-icon name="loader-2" /><span>{{ __('share.saving') }}</span></button>
        </div>
    </x-window>
    </div>
@endsection
