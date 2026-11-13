{{-- Point 123: Texterkennung as a module of its own, opened by the OCR widget below Abmelden – the same content as
     Einstellungen → Texterkennung, in one window without the settings columns. --}}
@extends('layouts.app')

@section('title', __('ocr.title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
        <x-window :title="__('ocr.title')" icon="text-scan-2" kind="ocr" :url="route('ocr.live')" :close="route('home')" optimal="48rem" max="64rem">
            @include('settings._ocr')
        </x-window>
    </div>
@endsection
