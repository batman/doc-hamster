@extends('layouts.app')

@section('title', __('phone_check.qr_title'))

@push('head')
    {{-- A new test code every 30 seconds, like the later pairing code. --}}
    <meta http-equiv="refresh" content="30">
@endpush

@section('content')
    <h1>{{ __('phone_check.qr_title') }}</h1>

    <h2>{{ __('phone_check.qr_step_page') }}</h2>
    <p>{{ __('phone_check.qr_step_page_hint') }}</p>
    <img class="phone-qr" src="{{ $pageQr }}" alt="{{ __('phone_check.qr_step_page') }}">

    <h2>{{ __('phone_check.qr_step_test') }}</h2>
    <p>{{ __('phone_check.qr_step_test_hint') }}</p>
    <img class="phone-qr" src="{{ $testQr }}" alt="{{ __('phone_check.qr_step_test') }}">
@endsection
