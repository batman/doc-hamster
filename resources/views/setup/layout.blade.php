<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('setup.wizard.title') }} – {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/setup.css') }}">
</head>
<body>
    <main class="setup">
        <p class="setup-brand">{{ config('app.name') }} · {{ __('setup.wizard.title') }}</p>
        @yield('content')
    </main>
</body>
</html>
