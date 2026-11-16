@extends('layouts.settings')

@section('title', __('phone_check.qr_title'))

@push('head')
    {{-- A new test code every 30 seconds, like the later pairing code. --}}
    <meta http-equiv="refresh" content="30">
@endpush

@section('page')

    <h2>{{ __('phone_check.qr_step_page') }}</h2>
    <p>{{ __('phone_check.qr_step_page_hint') }}</p>
    <figure class="qr-card"><img src="{{ $pageQr }}" alt="{{ __('phone_check.qr_step_page') }}" width="240" height="240"><figcaption>{{ __('phone_check.qr_step_page') }}</figcaption></figure>

    <h2>{{ __('phone_check.qr_step_test') }}</h2>
    <p>{{ __('phone_check.qr_step_test_hint') }}</p>
    <figure class="qr-card"><img src="{{ $testQr }}" alt="{{ __('phone_check.qr_step_test') }}" width="240" height="240"><figcaption>{{ __('phone_check.qr_step_test') }}</figcaption></figure>
@endsection
