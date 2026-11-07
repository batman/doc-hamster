{{-- A request from the window system (header X-DocHamster-Window) gets only the content, no menu or frame (A73). --}}
@if (request()->header('X-DocHamster-Window') === '1')
@yield('content')
@else
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#30302e">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('img/apple-touch-icon.png') }}">
    <title>@hasSection('title')@yield('title') – @endif{{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('dochamster.version') }}">
    @auth
        <link rel="stylesheet" href="{{ route('tags.css') }}">
    @endauth
    <script src="{{ asset('js/app.js') }}?v={{ config('dochamster.version') }}"></script>
    @stack('head')
</head>
<body>
    <a class="skip-link" href="#main">{{ __('app.skip_to_content') }}</a>
    <div class="shell">
        @auth
            @php $badges = \App\Http\Badges::counts(app(\App\Domain\Org\CurrentOrg::class)); @endphp
            <aside class="side" data-badges="{{ route('badges') }}">
                <header class="site-header">
                    <button type="button" class="nav-toggle" aria-controls="nav" aria-expanded="true" aria-label="{{ __('app.nav.toggle') }}"><x-icon name="menu-2" /><span class="nav-dot" data-badge-any @unless (array_sum($badges) > 0) hidden @endunless></span></button>
                    <a class="brand" href="{{ route('home') }}"><img src="{{ asset('img/logo.png') }}" alt="{{ config('app.name') }}" width="476" height="186"></a>
                </header>
                <nav id="nav" class="sidebar" aria-label="{{ __('app.nav.label') }}">
                    @can('documents.upload')
                        <form class="nav-upload" method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" data-busy="{{ __('documents.uploading') }}">
                            @csrf
                            {{-- Opened inside a folder: new uploads go there (issue #77). --}}
                            @if (is_string(request()->query('folder')) && request()->query('folder') !== 'none')
                                <input type="hidden" name="folder" value="{{ request()->query('folder') }}">
                            @endif
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
                            ['home', 'home', 'home.title', null, ['home', 'notes.*'], null],
                            ['documents.index', 'receipt', 'documents.title', null, ['documents.*'], null],
                            ['print-tray.index', 'printer', 'print_tray.title', 'documents.upload', ['print-tray.*'], \App\Http\Badges::PRINT_TRAY],
                            ['bank.index', 'building-bank', 'bank.title', 'banking.import', ['bank.index', 'bank.show'], null],
                            ['settings.index', 'settings', 'settings.title', null, ['settings.*', 'members.*', 'roles.*', 'tags.*', 'exports.*', 'updates.*', 'bank.import*', 'bank.accounts.*', 'phone-check*', 'folders.*'], null],
                        ] as [$route, $icon, $label, $ability, $active, $badge])
                            @if ($ability === null || Gate::allows($ability))
                                <li><a class="nav-item tooltip-right" href="{{ route($route) }}" data-tooltip="{{ __($label) }}" @if (request()->routeIs(...$active) && ! ($route === 'documents.index' && (request()->filled('folder') || request()->routeIs('documents.trash')))) aria-current="page" @endif><x-icon :name="$icon" /><span class="nav-text">{{ __($label) }}</span> @if ($badge !== null) @php $count = $badges[$badge] ?? 0; @endphp <span class="nav-badge" data-badge="{{ $badge }}" @if ($count === 0) hidden @endif>{{ $count > 9 ? '9+' : $count }}</span> @endif</a></li>
                                @if ($route === 'documents.index' && app(\App\Domain\Org\CurrentOrg::class)->isSet())
                                    {{-- Submenu "Ordner" under Belege (PO, 2026-11-07): the folders in their order, the Papierkorb among them, "+" for a new one. --}}
                                    @php
                                        $menuOrg = app(\App\Domain\Org\CurrentOrg::class)->organization();
                                        $menuRows = \App\Domain\Documents\Folder::menu($menuOrg);
                                        $menuFolders = collect($menuRows)->pluck('folder')->filter();
                                        $menuOpen = [];
                                        $menuWanted = request()->query('folder');
                                        for ($menuStep = is_string($menuWanted) ? $menuFolders->firstWhere('id', $menuWanted) : null; $menuStep !== null; $menuStep = $menuFolders->firstWhere('id', $menuStep->parent_id)) {
                                            $menuOpen[] = $menuStep->id;
                                        }
                                        $menuEdit = Gate::allows('documents.edit');
                                    @endphp
                                    <li class="nav-sub" data-folder-order="{{ $menuEdit ? route('folders.order') : '' }}" data-order-failed="{{ __('folders.order_invalid') }}" data-selected-text="{{ __('folders.selected') }}">
                                        <div class="nav-sub-head">
                                            <span class="nav-text">{{ __('folders.title') }}</span>
                                            @if ($menuEdit)
                                                <a class="nav-folder-add is-visible" href="{{ route('folders.index') }}" aria-label="{{ __('folders.add') }}" title="{{ __('folders.add') }}"><x-icon name="plus" /></a>
                                            @endif
                                        </div>
                                        @include('folders._menu', [
                                            'items' => collect($menuRows)->where('depth', 0)->pluck('folder')->all(),
                                            'children' => $menuFolders->filter(static fn ($f): bool => $f->parent_id !== null)->groupBy('parent_id'),
                                            'open' => $menuOpen,
                                            'edit' => $menuEdit,
                                            'parent' => null,
                                            'depth' => 0,
                                        ])
                                        <span class="visually-hidden" data-folder-live aria-live="polite"></span>
                                    </li>
                                @endif
                            @endif
                        @endforeach
                    </ul>
                    <form class="nav-logout" method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="nav-item tooltip-right" data-tooltip="{{ __('auth.logout') }}"><x-icon name="power" /><span class="nav-text">{{ __('auth.logout') }}</span></button>
                    </form>
                    <small class="nav-version tooltip-right" data-tooltip="{{ __('app.version', ['version' => config('dochamster.version')]) }}"><span class="nav-text">{{ __('app.version', ['version' => config('dochamster.version')]) }}</span></small>
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
            <main id="main" class="@yield('main_class', 'page')">
                @if (isset($errors) && $errors->hasAny(['file', 'files', 'files.*']))
                    <p class="error" role="alert">{{ $errors->first('file') ?: ($errors->first('files') ?: $errors->first('files.*')) }}</p>
                @endif
                @yield('content')
            </main>
            @guest
                <footer class="site-footer"><small class="muted">{{ config('app.name') }} {{ __('app.version', ['version' => config('dochamster.version')]) }}</small></footer>
            @endguest
        </div>
    </div>
</body>
</html>
@endif
