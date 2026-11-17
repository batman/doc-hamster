{{-- Back to the assistant after Erlauben / Ablehnen (D-040): a page that moves on by itself (the site's form-action
     rule lets a form lead only to docHamster), with the link in case it doesn't. --}}
@extends('layouts.app')

@section('title', __('assistant.consent.title'))

@push('head')
    <meta http-equiv="refresh" content="0; url={{ $url }}">
@endpush

@section('content')
    <h1>{{ $denied ? __('assistant.back.denied', ['name' => $client->name]) : __('assistant.back.allowed', ['name' => $client->name]) }}</h1>
    <p>{{ __('assistant.back.text', ['name' => $client->name]) }} <a href="{{ $url }}">{{ __('assistant.back.go', ['name' => $client->name]) }}</a></p>
@endsection
