{{--
    One window of the window system (Go 2026-11-07, points 22 and 24, see docs/design/FRAMEWORK.md).
    A module only fills the body; title, icon, close, strip, history and animation come from the framework.
    Props: title, icon (Tabler name), url (this window's address, default the current one),
    close (where the X leads without JavaScript; null = the window can't be closed), slot "actions" for the toolbar,
    upload (where an upload belongs while this window is active, e.g. "folder=01H…"; sent as extra form fields),
    min, optimal, max, priority (point 54: the widths the automatic layout uses, e.g. min="36rem"; the higher the
    priority the later the window becomes a strip; defaults per kind below),
    search (placeholder of a search field in the toolbar, A104: typing reloads the window body with ?q=…; the module reads q).
    Head like Windows (points 70, 72): title (ellipsis), then the three window controls minimise (‹), maximise, X.
    Search, actions and the module's ⋯ menu (slot "menu"; the framework adds "Notizen daneben öffnen" on top) stand in
    a toolbar row below the head that follows the window's own width (container queries, point 41).
    One window = one instance of a module for one record; the same record can be open twice (point 40), so ids inside
    a window loaded later get a per-window suffix in app.js – write plain ids, dialog names and labels as usual.
    kind (the module type, e.g. "folder", "detail"; default the route name): picks the default widths; a dragged width belongs only to that one window (A97).
--}}
@props(['title', 'icon' => 'file-text', 'url' => null, 'close' => null, 'upload' => null, 'min' => null, 'optimal' => null, 'max' => null, 'priority' => null, 'search' => null, 'kind' => null])
@php
    $url ??= request()->fullUrl();
    $kind ??= request()->route()?->getName() ?? 'page';
    // Widths per module kind (point 54): [min, optimal, max] in rem and the priority. Lists narrower, documents wider.
    [$minRem, $optRem, $maxRem, $prio] = match ($kind) {
        'folder' => [20, 26, 34, 1],
        'trash', 'mail' => [22, 30, 40, 1],
        'detail' => [30, 44, 64, 2],
        'text' => [32, 48, 72, 2],
        'notes' => [22, 40, 96, 1],
        default => [24, 32, 48, 1],
    };
    $min ??= $minRem.'rem';
    $optimal ??= $optRem.'rem';
    $max ??= $maxRem.'rem';
    $priority ??= $prio;
    // The search sends q; the window's other query fields go along, so a search without JavaScript keeps them (A104).
    $searchKeep = [];
    $searchTerm = '';
    if ($search) {
        foreach (array_filter(explode('&', (string) parse_url($url, PHP_URL_QUERY))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            $value = urldecode($value);
            if ($key === 'q') {
                $searchTerm = $value;
            } else {
                $searchKeep[] = [$key, $value];
            }
        }
    }
@endphp
<section {{ $attributes->class('win') }} data-window data-window-url="{{ $url }}" data-window-title="{{ $title }}" @if ($upload) data-window-upload="{{ $upload }}" @endif data-window-min="{{ $min }}" data-window-opt="{{ $optimal }}" data-window-max="{{ $max }}" data-window-priority="{{ $priority }}" data-window-kind="{{ $kind }}" aria-label="{{ $title }}">
    <button type="button" class="win-strip" data-window-activate aria-label="{{ __('app.window.open', ['title' => $title]) }}">
        <x-icon name="chevron-right" />
        <span class="strip-label"><x-icon :name="$icon" /><span>{{ $title }}</span></span>
    </button>
    @if ($close)
        <button type="button" class="win-strip-x" data-window-close aria-label="{{ __('app.window.remove', ['title' => $title]) }}" data-tooltip="{{ __('app.window.remove_short') }}"><x-icon name="x" /></button>
    @endif
    <div class="win-frame">
        <header class="win-head">
            <button type="button" class="win-back icon-button" data-window-back aria-label="{{ __('app.window.back') }}" hidden><x-icon name="chevron-left" /></button>
            <x-icon :name="$icon" />
            <h1 class="win-title" title="{{ $title }}">{{ $title }}</h1>
            {{-- The window controls (points 70, 72, A116-A124): frameless, always this order; on phones only X. --}}
            <button type="button" class="control-button win-minimise" data-window-fold aria-label="{{ __('app.window.minimise') }}" data-tooltip="{{ __('app.window.minimise') }}"><x-icon name="chevron-left" /></button>
            <button type="button" class="control-button win-maximise" data-window-maximise aria-pressed="false" aria-label="{{ __('app.window.maximise') }}" data-tooltip="{{ __('app.window.maximise') }}" data-label-max="{{ __('app.window.maximise') }}" data-label-restore="{{ __('app.window.restore') }}"><x-icon name="window-maximise" /><x-icon name="window-restore" /></button>
            @if ($close)
                <a class="control-button win-close" href="{{ $close }}" data-window-close aria-label="{{ __('app.window.close') }}" data-tooltip="{{ __('app.window.close') }}"><x-icon name="x" /></a>
            @endif
        </header>
        <div class="win-tools">
            @if ($search)
                <form class="win-search" role="search" method="get" action="{{ strtok($url, '?') }}" data-window-search>
                    @foreach ($searchKeep as [$key, $value])
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endforeach
                    <input type="search" class="input-small" name="q" value="{{ $searchTerm }}" placeholder="{{ $search }}" aria-label="{{ $search }}" autocomplete="off" enterkeyhint="search">
                    <button type="button" class="win-search-clear" data-window-search-clear aria-label="{{ __('app.window.search_clear') }}" data-tooltip="{{ __('app.window.search_clear') }}" @if ($searchTerm === '') hidden @endif><x-icon name="x" /></button>
                </form>
            @endif
            <div class="win-actions">
                {{ $actions ?? '' }}
                {{-- The module's ⋯ menu (points 71, 73): the framework's entries on top, then the module's own (slot "menu"). --}}
                <x-more-menu class="win-menu">
                    <a class="menu-item" href="{{ route('home') }}" data-window><x-icon name="note" /><span>{{ __('app.window.notes_beside') }}</span></a>
                    @if (isset($menu) && $menu->hasActualContent())
                        <hr class="menu-separator">
                        {{ $menu }}
                    @endif
                </x-more-menu>
            </div>
        </div>
        <div class="win-body">{{ $slot }}</div>
    </div>
    {{-- The drag edge on the right (point 44): drag, arrow keys, double-click = default width. --}}
    <div class="win-edge" data-window-edge role="separator" aria-orientation="vertical" tabindex="0" aria-label="{{ __('app.window.resize') }}" title="{{ __('app.window.resize_hint') }}"></div>
</section>
