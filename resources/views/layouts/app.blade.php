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
    @auth
        {{-- Point 115: where core/error-report.js sends browser errors (Einstellungen → Log). --}}
        <meta name="log-client" content="{{ route('log.client') }}">
    @endauth
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('img/apple-touch-icon.png') }}">
    {{-- Point 151: the app installs on the home screen and is a share target on Android (core/pwa.js registers sw.js). --}}
    @unless (request()->routeIs('phone-check'))
        <link rel="manifest" href="{{ route('manifest') }}" data-sw="{{ asset('sw.js') }}">
    @endunless
    <title>@hasSection('title')@yield('title') – @endif{{ config('app.name') }}</title>
    @foreach (config('assets.css') as $file)
        <link rel="stylesheet" href="{{ asset('css/'.$file.'.css') }}?v={{ config('dochamster.version') }}">
    @endforeach
    @auth
        {{-- Point 164.4: versioned, so the browser keeps it until a colour changes (no request per page). --}}
        <link rel="stylesheet" href="{{ route('tags.css', ['v' => \App\Http\TagsCss::version()]) }}">
    @endauth
    @foreach (config('assets.js') as $file)
        <script src="{{ asset('js/'.$file.'.js') }}?v={{ config('dochamster.version') }}"></script>
    @endforeach
    @stack('head')
</head>
{{-- Browser OCR fallback (point 18): only when the server can't read text itself (in mode app: the PDFs, point 69). Without cron the open app works off the queue (A69/A74). --}}
@php
    // Point 164.4: the cache values this layout reads, in one query (the database cache store would ask once per key).
    if (auth()->check()) {
        $layoutOrg = app(\App\Domain\Org\CurrentOrg::class);
        \Illuminate\Support\Facades\Cache::memo()->many(array_values(array_filter([
            \App\Infrastructure\Environment\LocalOcr::MODE_KEY, \App\Infrastructure\Environment\LocalOcr::STATUS_KEY, \App\Infrastructure\Environment\LocalOcr::BROWSER_ERROR_KEY,
            \App\Infrastructure\Environment\NativeSystemProbe::HEARTBEAT_CACHE_KEY, \App\Http\TagsCss::KEY,
            $layoutOrg->isSet() ? \App\Infrastructure\Environment\OcrProgress::waitingKey($layoutOrg->id()) : null,
        ])));
    }
    $ocrBrowser = auth()->check() && app(\App\Infrastructure\Environment\LocalOcr::class)->browserLoop();
    $ocrEngineError = $ocrBrowser ? app(\App\Infrastructure\Environment\LocalOcr::class)->browserError() : null;
    $queueTick = auth()->check() && ! \App\Queue\CronClock::running();
@endphp
<body data-pdfjs="{{ asset('vendor/pdfjs') }}" @if ($ocrBrowser) data-ocr-browser="{{ asset('vendor/tesseract') }}" data-ocr-next="{{ route('ocr.next') }}" data-ocr-engine="{{ route('ocr.engine') }}" data-ocr-log="{{ route('ocr.log') }}" @endif @if ($queueTick) data-queue-tick="{{ route('queue.tick') }}" @endif>
    <a class="skip-link" href="#main">{{ __('app.skip_to_content') }}</a>
    <div class="shell">
        @auth
            @php
                $currentOrg = app(\App\Domain\Org\CurrentOrg::class);
                $badges = \App\Http\Badges::counts($currentOrg);
                // OCR indicator below Abmelden (point 57): one cached query, shown only while documents are open.
                $ocrOpen = $currentOrg->isSet() ? \App\Infrastructure\Environment\OcrProgress::open($currentOrg->id()) : ['open' => 0, 'total' => 0, 'done' => 0];
                $ocrTip = __('ocr.nav_tooltip', ['count' => $ocrOpen['open']]).($ocrEngineError !== null ? ' · '.__('ocr.engine_error', ['reason' => $ocrEngineError]) : '');
            @endphp
            <aside class="side" data-badges="{{ route('badges') }}">
                <header class="site-header">
                    <button type="button" class="nav-toggle" aria-controls="nav" aria-expanded="true" aria-label="{{ __('app.nav.toggle') }}"><x-icon name="menu-2" /><span class="nav-dot" data-badge-any @unless (array_sum($badges) > 0) hidden @endunless></span></button>
                    <a class="brand" href="{{ route('home') }}"><img src="{{ asset('img/logo.png') }}" alt="{{ config('app.name') }}" width="476" height="186"></a>
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
                        {{-- Drop field over the main menu while files are dragged (A169): they go into "Dokumente allgemein". --}}
                        <div class="nav-drop" data-nav-drop hidden><x-icon name="upload" /><span class="nav-text">{{ __('documents.drop') }}</span></div>
                    @endcan
                    <ul class="nav-list">
                        {{-- Everything else (people, roles, tags, bank import, export, update, phone test) sits under Einstellungen. --}}
                        @foreach ([
                            ['home', 'home', 'home.title', null, ['home', 'notes.*'], null],
                            ['documents.index', 'file-text', 'documents.title', null, ['documents.*', 'folders.*'], null],
                            ['inbox.index', 'inbox', 'inbox.title', 'documents.upload', ['inbox.index'], \App\Http\Badges::INBOX],
                            ['bank.index', 'building-bank', 'bank.title', 'banking.import', ['bank.index', 'bank.show', 'bank.account', 'bank.receipts', 'bank.import.columns'], \App\Http\Badges::BANK],
                            ['settings.index', 'settings', 'settings.title', null, ['settings.*', 'members.*', 'roles.*', 'tags.*', 'exports.*', 'updates.*', 'ocr.show', 'palette.*', 'bank.import', 'bank.accounts.*', 'phone-check*', 'log.*', 'printers.*', 'inbox.mail'], \App\Http\Badges::LOG],
                        ] as [$route, $icon, $label, $ability, $active, $badge])
                            @if ($ability === null || Gate::allows($ability))
                                <li><a class="nav-item tooltip-right" href="{{ route($route) }}" data-tooltip="{{ __($label) }}" @if (request()->routeIs(...$active) || ($route === 'settings.index' && \App\Http\SettingsMenu::owns(request()))) aria-current="page" @endif><x-icon :name="$icon" /><span class="nav-text">{{ __($label) }}</span> @if ($badge !== null) @php $count = $badges[$badge] ?? 0; @endphp <span class="badge badge-alert" data-badge="{{ $badge }}" @if ($count === 0) hidden @endif>{{ $count > 9 ? '9+' : $count }}</span> @endif</a></li>
                            @endif
                        @endforeach
                    </ul>
                    <form class="nav-logout" method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="nav-item tooltip-right" data-tooltip="{{ __('auth.logout') }}"><x-icon name="power" /><span class="nav-text">{{ __('auth.logout') }}</span></button>
                    </form>
                    {{-- Package G: OCR indicator (point 57); the pause button only where this browser reads (point 18). --}}
                    <div class="nav-ocr" data-ocr-nav="{{ route('ocr.status') }}" data-ocr-open="{{ $ocrOpen['open'] }}"
                        data-label="{{ __('ocr.nav_open', ['count' => ':count']) }}" data-tooltip-label="{{ __('ocr.nav_tooltip', ['count' => ':count']) }}" data-paused-label="{{ __('ocr.nav_paused') }}"
                        data-engine-error="{{ $ocrEngineError !== null ? __('ocr.engine_error', ['reason' => $ocrEngineError]) : '' }}" data-engine-error-label="{{ __('ocr.engine_error', ['reason' => ':reason']) }}"
                        @if ($ocrOpen['open'] === 0) hidden @endif>
                        {{-- Point 123: a click opens Texterkennung as a module of its own (no settings columns). --}}
                        @php $ocrLink = Gate::allows('settings.manage'); @endphp
                        <{{ $ocrLink ? 'a' : 'span' }} class="nav-ocr-link" @if ($ocrLink) href="{{ route('ocr.live') }}" @if (request()->routeIs('ocr.live')) aria-current="page" @endif @endif>
                            <span class="nav-ocr-icon tooltip-right" data-ocr-icon data-tooltip="{{ $ocrTip }}" role="img" aria-label="{{ $ocrTip }}"><x-icon name="search" /></span>
                            <span class="nav-ocr-body nav-text">
                                <span class="nav-ocr-title">{{ __('ocr.nav_title') }}</span>
                                <span class="nav-ocr-count" data-ocr-open-text>{{ __('ocr.nav_open', ['count' => $ocrOpen['open']]) }}</span>
                            </span>
                        </{{ $ocrLink ? 'a' : 'span' }}>
                        @if ($ocrBrowser)
                            <button type="button" class="nav-ocr-toggle tooltip-right" data-ocr-toggle data-pause="{{ __('ocr.pause') }}" data-resume="{{ __('ocr.resume') }}" data-tooltip="{{ __('ocr.pause') }}" aria-label="{{ __('ocr.pause') }}" aria-pressed="false"><x-icon name="player-pause" /><x-icon name="player-play" /></button>
                        @endif
                        <progress class="nav-ocr-bar" max="{{ max(1, $ocrOpen['total']) }}" value="{{ $ocrOpen['done'] }}" data-ocr-nav-bar aria-label="{{ __('ocr.nav_title') }}"></progress>
                    </div>
                    <small class="nav-version tooltip-right" data-tooltip="{{ __('app.version', ['version' => config('dochamster.version')]) }}"><span class="nav-text">{{ __('app.version', ['version' => config('dochamster.version')]) }}</span></small>
                    <button type="button" class="nav-collapse" aria-label="{{ __('app.nav.collapse') }}" data-collapse="{{ __('app.nav.collapse') }}" data-expand="{{ __('app.nav.expand') }}"><x-icon name="chevron-left" /></button>
                </nav>
            </aside>
            <div class="nav-backdrop"></div>
            @if ($currentOrg->isSet() && Gate::any(['documents.upload', 'documents.edit', 'banking.import']))
                {{-- Drag and drop of documents, folders and files (points 75-87): addresses and texts for app.js. No overlay any more:
                     files land on a folder row, a folder window or the main menu's drop field (A169-A175).
                     Point 160: data-org marks drags between browser tabs (same organisation only), data-doc-url builds
                     the document address for text/uri-list, data-bank allows links to bookings. --}}
                @php
                    // Point 164.4: id and name in one query.
                    $defaultRow = \App\Domain\Documents\Folder::query()->where('is_default', true)->first(['id', 'name']);
                    $defaultFolder = $defaultRow?->id ?? \App\Domain\Documents\Folder::defaultIdFor($currentOrg->id());
                    $defaultName = $defaultRow?->name ?? \App\Domain\Documents\Folder::query()->whereKey($defaultFolder)->value('name');
                @endphp
                <div hidden data-dnd data-org="{{ $currentOrg->id() }}" data-doc-url="{{ route('documents.show', '__ID__') }}"
                     @can('banking.import') data-bank="1" @endcan
                     @can('documents.upload') data-upload="{{ route('documents.store') }}" @endcan
                     @can('documents.edit') data-move="{{ route('documents.bulk.move') }}" data-folder-move="{{ route('folders.move', '__ID__') }}" @endcan
                     @can('documents.trash') data-trash="{{ route('documents.bulk.trash') }}" data-restore="{{ route('documents.bulk.restore') }}" data-folder-destroy="{{ route('folders.destroy', '__ID__') }}" @endcan
                     data-default-folder="{{ $defaultFolder }}" data-default-name="{{ $defaultName }}"
                     data-drop-upload="{{ __('documents.bulk.drop_upload', ['name' => ':name']) }}"
                     data-max-depth="{{ \App\Domain\Documents\Folder::MAX_DEPTH }}"
                     data-docs-one="{{ trans_choice('documents.bulk.count', 1, ['count' => ':count']) }}" data-docs-many="{{ trans_choice('documents.bulk.count', 2, ['count' => ':count']) }}"
                     data-uploaded-one="{{ trans_choice('documents.bulk.uploaded', 1, ['count' => ':count', 'name' => ':name']) }}" data-uploaded-many="{{ trans_choice('documents.bulk.uploaded', 2, ['count' => ':count', 'name' => ':name']) }}"
                     data-uploading="{{ __('documents.bulk.uploading') }}" data-undo="{{ __('documents.bulk.undo') }}" data-undone="{{ __('documents.bulk.undone') }}" data-failed="{{ __('documents.bulk.failed') }}"
                     data-too-deep="{{ __('folders.too_deep', ['max' => \App\Domain\Documents\Folder::MAX_DEPTH + 1]) }}" data-cancel="{{ __('documents.bulk.cancel') }}"
                     data-folder-trash-title="{{ __('folders.drop_trash_title') }}" data-folder-trash-text="{{ __('folders.drop_trash_text', ['name' => ':name']) }}" data-folder-trash-ok="{{ __('folders.drop_trash') }}"></div>
            @endif
            @if ($currentOrg->isSet() && Gate::allows('documents.edit'))
                {{-- The popup "Markieren als" (DocHamster.markAs, point 122.4): addresses and texts for properties.js. --}}
                <div hidden data-properties-api
                     data-card="{{ route('documents.properties', '__ID__') }}" data-set="{{ route('documents.properties.set', '__ID__') }}" data-preview="{{ route('documents.properties.preview', '__ID__') }}"
                     data-types="{{ json_encode(__('properties.types')) }}"
                     data-title="{{ __('properties.mark.title') }}" data-property="{{ __('properties.mark.property') }}" data-value="{{ __('properties.mark.value') }}"
                     data-save="{{ __('properties.mark.save') }}" data-cancel="{{ __('properties.cancel') }}" data-none="{{ __('properties.mark.none') }}"
                     data-pick="{{ __('properties.mark.pick') }}" data-preview-text="{{ __('properties.mark.preview', ['value' => ':value']) }}"
                     data-replaces="{{ __('properties.mark.replaces', ['value' => ':value']) }}" data-failed="{{ __('app.edit_in_place.failed') }}"></div>
            @endif
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
