<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#30302e">
    <title>@hasSection('title')@yield('title') – @endif{{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script src="{{ asset('js/hamster.js') }}"></script>
    <script src="{{ asset('js/app.js') }}"></script>
    @stack('head')
</head>
<body>
    <a class="skip-link" href="#main">{{ __('app.skip_to_content') }}</a>
    <header class="site-header">
        @auth
            <button type="button" class="nav-toggle" aria-controls="nav" aria-expanded="true" aria-label="{{ __('app.nav.toggle') }}"><x-icon name="menu-2" /></button>
        @endauth
        <a class="brand" href="{{ url('/') }}"><img src="{{ asset('img/logo.png') }}" alt="{{ config('app.name') }}" width="290" height="75"></a>
        @yield('header')
    </header>
    <div class="shell">
        @auth
            <nav id="nav" class="sidebar" aria-label="{{ __('app.nav.label') }}">
                @can('documents.upload')
                    <form class="nav-upload" method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" data-busy="{{ __('documents.uploading') }}">
                        @csrf
                        <label class="nav-item nav-action tooltip-right" data-tooltip="{{ __('documents.upload') }}">
                            <input class="visually-hidden" type="file" name="files[]" multiple accept="image/*,application/pdf">
                            <x-icon name="upload" /><span class="nav-text">{{ __('documents.upload') }}</span>
                        </label>
                        <noscript><button type="submit">{{ __('documents.upload') }}</button></noscript>
                    </form>
                @endcan
                <ul class="nav-list">
                    @foreach ([
                        ['documents.index', 'receipt', 'documents.title', null],
                        ['bank.index', 'building-bank', 'bank.title', 'banking.import'],
                        ['exports.form', 'file-export', 'exports.title', 'exports.create'],
                        ['tags.index', 'tags', 'tags.title', 'tags.manage'],
                    ] as [$route, $icon, $label, $ability])
                        @if ($ability === null || Gate::allows($ability))
                            <li><a class="nav-item tooltip-right" href="{{ route($route) }}" data-tooltip="{{ __($label) }}" @if (request()->routeIs(Str::before($route, '.').'.*')) aria-current="page" @endif><x-icon :name="$icon" /><span class="nav-text">{{ __($label) }}</span></a></li>
                        @endif
                    @endforeach
                </ul>
                @canany(['members.manage', 'settings.manage'])
                    <ul class="nav-list">
                        @foreach ([
                            ['members.index', 'users', 'members.title', 'members.manage'],
                            ['roles.index', 'user-shield', 'roles.title', 'members.manage'],
                            ['updates.show', 'cloud-download', 'updates.title', 'settings.manage'],
                        ] as [$route, $icon, $label, $ability])
                            @can($ability)
                                <li><a class="nav-item tooltip-right" href="{{ route($route) }}" data-tooltip="{{ __($label) }}" @if (request()->routeIs(Str::before($route, '.').'.*')) aria-current="page" @endif><x-icon :name="$icon" /><span class="nav-text">{{ __($label) }}</span></a></li>
                            @endcan
                        @endforeach
                    </ul>
                @endcanany
                <form class="nav-logout" method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="nav-item tooltip-right" data-tooltip="{{ __('auth.logout') }}"><x-icon name="power" /><span class="nav-text">{{ __('auth.logout') }}</span></button>
                </form>
                <button type="button" class="nav-collapse" aria-label="{{ __('app.nav.collapse') }}" data-collapse="{{ __('app.nav.collapse') }}" data-expand="{{ __('app.nav.expand') }}"><x-icon name="chevron-left" /></button>
            </nav>
            <div class="nav-backdrop"></div>
            @can('documents.upload')
                <div class="drop-overlay" hidden><div class="drop-hamster"></div><p>{{ __('documents.drop') }}</p></div>
            @endcan
        @endauth
        <div class="content">
            <main id="main" class="page">
                @if (isset($errors) && $errors->hasAny(['file', 'files', 'files.*']))
                    <p class="error" role="alert">{{ $errors->first('file') ?: ($errors->first('files') ?: $errors->first('files.*')) }}</p>
                @endif
                @yield('content')
            </main>
            <footer class="site-footer">
                <hr>
                <small class="muted">{{ config('app.name') }} {{ __('app.version', ['version' => config('dochamster.version')]) }}</small>
            </footer>
        </div>
    </div>
</body>
</html>
