<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light dark">
    <title>@hasSection('title')@yield('title') – @endif{{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @stack('head')
</head>
<body>
    <a class="skip-link" href="#main">{{ __('app.skip_to_content') }}</a>
    <header class="site-header">
        <a class="brand" href="{{ url('/') }}">{{ config('app.name') }}</a>
        @yield('header')
        @auth
            <a href="{{ url('/') }}">{{ __('documents.title') }}</a>
            @can('exports.create')
                <a href="{{ route('exports.form') }}">{{ __('exports.title') }}</a>
            @endcan
            @can('members.manage')
                <a href="{{ route('members.index') }}">{{ __('members.title') }}</a>
                <a href="{{ route('roles.index') }}">{{ __('roles.title') }}</a>
            @endcan
            @can('settings.manage')
                <a href="{{ route('updates.show') }}">{{ __('updates.title') }}</a>
            @endcan
            <form class="logout" method="post" action="{{ route('logout') }}">
                @csrf
                <button type="submit">{{ __('auth.logout') }}</button>
            </form>
        @endauth
    </header>
    <main id="main" class="page">
        @yield('content')
    </main>
    <footer class="site-footer">
        <hr>
        <small class="muted">{{ config('app.name') }} {{ __('app.version', ['version' => config('dochamster.version')]) }}</small>
    </footer>
</body>
</html>
