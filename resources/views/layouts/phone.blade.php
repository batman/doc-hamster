{{-- The phone app (PHONE, issue #37): opened from the home screen. Only two places – Belege (camera)
     and Dokumente (folders, documents, scanning); everything else is the website. --}}
@php($offline = $offline ?? false)
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#30302e">
    <title>@hasSection('title')@yield('title') – @endif{{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('dochamster.asset_version') }}">
    @auth
        <link rel="stylesheet" href="{{ route('tags.css') }}">
    @endauth
    <link rel="manifest" href="{{ route('app.manifest') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/apple-touch-icon.png') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
    @unless ($offline)
        <meta name="csrf-token" content="{{ csrf_token() }}">
    @endunless
    <meta name="dh-sw" content="{{ asset('sw.js') }}?v={{ config('dochamster.asset_version') }}">
    <script src="{{ asset('js/app.js') }}?v={{ config('dochamster.asset_version') }}"></script>
    <script src="{{ asset('js/hamster.js') }}?v={{ config('dochamster.asset_version') }}" defer></script>
</head>
<body class="phone-app @yield('body_class')">
    @hasSection('head')
        <header class="phone-head">@yield('head')</header>
    @endif
    <main id="main" class="phone-main">
        @if (! $offline && session('status'))
            <p class="check-ok phone-flash" role="status">{{ session('status') }}</p>
        @endif
        @if (! $offline && isset($errors) && $errors->any())
            <p class="error phone-flash" role="alert">{{ $errors->first() }}</p>
        @endif
        @yield('content')
    </main>
    <nav class="phone-bar" aria-label="{{ __('app.bar.label') }}">
        <a class="phone-bar-item" href="{{ route('app.home') }}" @if (request()->routeIs('app.home') || $offline) aria-current="page" @endif><x-icon name="camera" /><span>{{ __('app.bar.receipts') }}</span></a>
        <a class="phone-bar-item" href="{{ route('app.documents') }}" @if (request()->routeIs('app.documents*', 'app.scan*', 'app.settings')) aria-current="page" @endif><x-icon name="folder" /><span>{{ __('app.bar.documents_app') }}</span></a>
    </nav>
    <div class="queue-status" role="status" hidden data-home="{{ route('app.home') }}"
        data-sending="{{ __('app.queue.sending') }}" data-sent="{{ __('app.queue.sent') }}"
        data-waiting="{{ trans_choice('app.queue.waiting', 1) }}" data-waiting-many="{{ trans_choice('app.queue.waiting', 2) }}" data-login="{{ __('app.queue.login') }}"
        data-failed="{{ __('app.queue.failed') }}" data-too-big="{{ __('app.queue.too_big') }}">
        <span class="queue-hamster" aria-hidden="true"></span><span class="queue-text"></span>
        <span class="queue-actions">
            <button type="button" class="button-quiet" data-queue-retry hidden>{{ __('app.queue.retry') }}</button>
            <button type="button" class="button-quiet" data-queue-drop hidden>{{ __('app.queue.drop') }}</button>
        </span>
    </div>
</body>
</html>
