{{--
    One window of the window system (Go 2026-11-07, points 22 and 24, see docs/design/FRAMEWORK.md).
    A module only fills the body; title, icon, close, strip, history and animation come from the framework.
    Props: title, icon (Tabler name), url (this window's address, default the current one),
    close (where the X leads without JavaScript; null = the window can't be closed), slot "actions" for the toolbar,
    upload (where an upload belongs while this window is active, e.g. "folder=01H…"; sent as extra form fields),
    min, optimal, max, priority (point 54: the widths the automatic layout uses, e.g. min="36rem"; the higher the
    priority the later the window becomes a strip; defaults per kind below),
    search (placeholder of a search field in the toolbar, A104: typing reloads the window body with ?q=…; the module reads q).
    Head like Windows (point 42): title (ellipsis), pin, minimise, X. Search and actions stand in a toolbar row below the
    head that follows the window's own width (container queries, point 41): one row, search on its own row, narrow.
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
        <span class="win-strip-pin"><x-icon name="pin-filled" /></span>
    </button>
    @if ($close)
        <button type="button" class="win-strip-x" data-window-close aria-label="{{ __('app.window.remove', ['title' => $title]) }}" data-tooltip="{{ __('app.window.remove_short') }}"><x-icon name="x" /></button>
    @endif
    <div class="win-frame">
        <header class="win-head">
            <button type="button" class="win-back icon-button" data-window-back aria-label="{{ __('app.window.back') }}" hidden><x-icon name="chevron-left" /></button>
            <x-icon :name="$icon" />
            <h1 class="win-title" title="{{ $title }}">{{ $title }}</h1>
            <button type="button" class="icon-button win-pin" data-window-pin aria-pressed="false" aria-label="{{ __('app.window.pin') }}" data-tooltip="{{ __('app.window.pin') }}" data-label-pin="{{ __('app.window.pin') }}" data-label-unpin="{{ __('app.window.unpin') }}"><x-icon name="pin" /><x-icon name="pin-filled" /></button>
            <button type="button" class="icon-button win-minimise" data-window-fold aria-label="{{ __('app.window.minimise') }}" data-tooltip="{{ __('app.window.minimise') }}"><x-icon name="minus" /></button>
            @if ($close)
                <a class="icon-button win-close" href="{{ $close }}" data-window-close aria-label="{{ __('app.window.close') }}" data-tooltip="{{ __('app.window.close') }}"><x-icon name="x" /></a>
            @endif
        </header>
        @if ($search || isset($actions))
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
                @isset($actions)
                    <div class="win-actions">{{ $actions }}</div>
                @endisset
            </div>
        @endif
        <div class="win-body">{{ $slot }}</div>
    </div>
    {{-- The drag edge on the right (point 44): drag, arrow keys, double-click = default width. --}}
    <div class="win-edge" data-window-edge role="separator" aria-orientation="vertical" tabindex="0" aria-label="{{ __('app.window.resize') }}" title="{{ __('app.window.resize_hint') }}"></div>
</section>
