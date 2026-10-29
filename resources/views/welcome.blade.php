<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name') }}</title>
</head>
<body>
    <main>
        <h1>{{ config('app.name') }}</h1>
        <p>{{ __('app.under_construction') }}</p>
    </main>
</body>
</html>
