{{-- E-Mail-Ablage (point 30): placeholder window until the function comes. --}}
@extends('layouts.app')

@section('title', __('mail_tray.title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    <x-window :title="__('mail_tray.title')" icon="mail" kind="mail" :url="route('mail-tray.index')">
        <p class="muted">{{ __('mail_tray.soon') }}</p>
    </x-window>
    </div>
@endsection
