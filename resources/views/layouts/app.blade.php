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
    <script src="{{ asset('js/app.js') }}"></script>
    @stack('head')
</head>
<body>
    <a class="skip-link" href="#main">{{ __('app.skip_to_content') }}</a>
    <div class="shell">
        @auth
            <aside class="side">
                <header class="site-header">
                    <button type="button" class="nav-toggle" aria-controls="nav" aria-expanded="true" aria-label="{{ __('app.nav.toggle') }}"><x-icon name="menu-2" /></button>
                    <a class="brand" href="{{ url('/') }}"><img src="{{ asset('img/logo.png') }}" alt="{{ config('app.name') }}" width="476" height="186"></a>
                </header>
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
                        {{-- Everything else (people, roles, tags, bank import, export, update, phone test) sits under Einstellungen. --}}
                        @foreach ([
                            ['documents.index', 'receipt', 'documents.title', null, ['documents.*']],
                            ['bank.index', 'building-bank', 'bank.title', 'banking.import', ['bank.index', 'bank.show']],
                            ['settings.index', 'settings', 'settings.title', null, ['settings.*', 'members.*', 'roles.*', 'tags.*', 'exports.*', 'updates.*', 'bank.import*', 'bank.accounts.*', 'phone-check*']],
                        ] as [$route, $icon, $label, $ability, $active])
                            @if ($ability === null || Gate::allows($ability))
                                <li><a class="nav-item tooltip-right" href="{{ route($route) }}" data-tooltip="{{ __($label) }}" @if (request()->routeIs(...$active)) aria-current="page" @endif><x-icon :name="$icon" /><span class="nav-text">{{ __($label) }}</span></a></li>
                            @endif
                        @endforeach
                    </ul>
                    <form class="nav-logout" method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="nav-item tooltip-right" data-tooltip="{{ __('auth.logout') }}"><x-icon name="power" /><span class="nav-text">{{ __('auth.logout') }}</span></button>
                    </form>
                    <button type="button" class="nav-collapse" aria-label="{{ __('app.nav.collapse') }}" data-collapse="{{ __('app.nav.collapse') }}" data-expand="{{ __('app.nav.expand') }}"><x-icon name="chevron-left" /></button>
                </nav>
            </aside>
            <div class="nav-backdrop"></div>
            @can('documents.upload')
                <div class="drop-overlay" hidden><p>{{ __('documents.drop') }}</p></div>
            @endcan
        @else
            <aside class="side side-plain">
                <header class="site-header"><a class="brand" href="{{ url('/') }}"><img src="{{ asset('img/logo.png') }}" alt="{{ config('app.name') }}" width="476" height="186"></a></header>
            </aside>
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
