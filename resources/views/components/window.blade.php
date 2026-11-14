{{--
    One window of the window system (Go 2026-11-07, points 22 and 24, see docs/design/FRAMEWORK.md).
    A module only fills the body; title, icon, close, strip, history and animation come from the framework.
    Props: title, icon (Tabler name), url (this window's address, default the current one),
    close (where the X leads without JavaScript; every module has one, point 112 – closing the last window leaves the area empty, A219), slot "actions" for the toolbar,
    upload (where an upload belongs while this window is active, e.g. "folder=01H…"; sent as extra form fields),
    min, optimal, max, priority (point 54: the widths the automatic layout uses, e.g. min="36rem"; the higher the
    priority the later the window becomes a strip; defaults per kind below),
    search (placeholder of a search field in the toolbar, A104: Enter or the magnifier reloads the window body with ?q=…; the module reads q),
    searchLive (true = typing already searches, after a short pause; default false, points 104/105),
    slot "searchOptions" (the advanced search, points 4-7: an area below the tools that the chevron beside the field slides open;
    its fields are sent with q), searchParams (the names of those fields, space-separated, "tags[]" for several values: kept out of
    the hidden fields and dropped, like q, when the search is closed) and searchCount (active filters: shown on the chevron, A3,
    and the area starts open).
    Head like Windows (points 70, 72): title (ellipsis), then the three window controls minimise (‹), maximise, X.
    Search, actions and the module's ⋯ menu (slot "menu"; no ⋯ button without entries) stand in
    a toolbar row below the head that follows the window's own width (container queries, point 41).
    One window = one instance of a module for one record; the same record can be open twice (point 40), so ids inside
    a window loaded later get a per-window suffix in app.js – write plain ids, dialog names and labels as usual.
    kind (the module type, e.g. "folder", "detail"; default the route name): picks the default widths.
    fit, content (point 199, "Platz folgt der Aufmerksamkeit"): fit = how wide the content wants to be – narrow (a few rows,
    ~22rem, never grows past it), medium (~44rem), wide (~56rem), full (as wide as possible) or a length –, kept within
    min/max; content = what the window shows (e.g. "folders", "documents", "gallery"); a dragged width is remembered per
    kind + content. Both may change with the body (a body reload brings the new ones).
    dragDoc, dragFile (point 160): the head drags this document (id, DownloadURL "mime:name:url") into other windows, tabs or the desktop.
    renameUrl, renameField, renameData (points 185.2, A364, "Fenstertitel umbenennen"): with a renameUrl the title is edited in place
    (core/window-rename.js) – POST renameData + renameField = the new name; give it only to people with edit rights.
--}}
@props(['title', 'icon' => 'file-text', 'url' => null, 'close' => null, 'upload' => null, 'min' => null, 'optimal' => null, 'max' => null, 'priority' => null, 'search' => null, 'searchLive' => false, 'searchParams' => '', 'searchCount' => 0, 'kind' => null, 'dragDoc' => null, 'dragFile' => null, 'renameUrl' => null, 'renameField' => 'name', 'renameData' => [], 'fit' => null, 'content' => null])
@php
    $url ??= request()->fullUrl();
    $kind ??= request()->route()?->getName() ?? 'page';
    // Widths per module kind (point 54): [min, optimal, max] in rem and the priority. Lists narrower, documents wider.
    [$minRem, $optRem, $maxRem, $prio] = match ($kind) {
        // A folder window shows a few folder rows or a whole document list or gallery; its fit hint says which (point 199).
        'folder' => [20, 26, 96, 1],
        'trash', 'mail' => [22, 30, 40, 1],
        'detail' => [30, 44, 64, 2],
        'text' => [32, 48, 72, 2],
        'notes' => [22, 40, 96, 1],
        'dashboard' => [22, 60, 160, 1],
        default => [24, 32, 48, 1],
    };
    $min ??= $minRem.'rem';
    $optimal ??= $optRem.'rem';
    $max ??= $maxRem.'rem';
    $priority ??= $prio;
    // The search sends q; the window's other query fields go along, so a search without JavaScript keeps them (A104).
    $searchKeep = [];
    $searchTerm = '';
    $searchOwned = array_values(array_filter(explode(' ', (string) $searchParams)));
    $searchSet = false;
    if ($search) {
        foreach (array_filter(explode('&', (string) parse_url($url, PHP_URL_QUERY))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = (string) preg_replace('/\[\d*\]$/', '[]', urldecode($key));
            $value = urldecode($value);
            if ($key === 'q') {
                $searchTerm = $value;
            } elseif (in_array($key, $searchOwned, true)) {
                $searchSet = $searchSet || $value !== '';
            } else {
                $searchKeep[] = [$key, $value];
            }
        }
    }
@endphp
<section {{ $attributes->class('win') }} data-window data-window-url="{{ $url }}" data-window-title="{{ $title }}" @if ($upload) data-window-upload="{{ $upload }}" @endif data-window-min="{{ $min }}" data-window-opt="{{ $optimal }}" data-window-max="{{ $max }}" data-window-priority="{{ $priority }}" data-window-kind="{{ $kind }}" @if ($fit) data-window-fit="{{ $fit }}" @endif @if ($content) data-window-content="{{ $content }}" @endif aria-label="{{ $title }}">
    <button type="button" class="win-strip" data-window-activate aria-label="{{ __('app.window.open', ['title' => $title]) }}">
        <x-icon name="chevron-right" />
        <span class="strip-label"><x-icon :name="$icon" /><span>{{ $title }}</span></span>
    </button>
    @if ($close)
        <button type="button" class="win-strip-x" data-window-close aria-label="{{ __('app.window.remove', ['title' => $title]) }}" data-tooltip="{{ __('app.window.remove_short') }}"><x-icon name="x" /></button>
    @endif
    <div class="win-frame">
        <header class="win-head" @if ($dragDoc) data-doc-drag="{{ $dragDoc }}" data-doc-file="{{ $dragFile }}" @endif>
            <button type="button" class="win-back icon-button" data-window-back aria-label="{{ __('app.window.back') }}" hidden><x-icon name="chevron-left" /></button>
            <x-icon :name="$icon" />
            <h1 class="win-title" title="{{ $title }}" @if ($dragDoc) data-doc-title @endif @if ($renameUrl) data-window-rename="{{ $renameUrl }}" data-rename-field="{{ $renameField }}" data-rename-data="{{ json_encode((object) $renameData) }}" data-rename-label="{{ __('app.window.rename') }}" data-rename-failed="{{ __('app.edit_in_place.failed') }}" tabindex="0" aria-description="{{ __('app.window.rename_hint') }}" @endif>{{ $title }}</h1>
            {{-- The window controls (points 70, 72, A116-A124): frameless, always this order; on phones only X. --}}
            <button type="button" class="control-button win-minimise" data-window-fold aria-label="{{ __('app.window.minimise') }}" data-tooltip="{{ __('app.window.minimise') }}"><x-icon name="chevron-left" /></button>
            <button type="button" class="control-button win-maximise" data-window-maximise aria-pressed="false" aria-label="{{ __('app.window.maximise') }}" data-tooltip="{{ __('app.window.maximise') }}" data-label-max="{{ __('app.window.maximise') }}" data-label-restore="{{ __('app.window.restore') }}"><x-icon name="window-maximise" /><x-icon name="window-restore" /></button>
            @if ($close)
                <a class="control-button win-close" href="{{ $close }}" data-window-close aria-label="{{ __('app.window.close') }}" data-tooltip="{{ __('app.window.close') }}"><x-icon name="x" /></a>
            @endif
        </header>
        <div class="win-tools">
            @if ($search)
                <form class="win-search" role="search" method="get" action="{{ strtok($url, '?') }}" data-window-search data-search-params="{{ implode(' ', $searchOwned) }}" @if ($searchLive) data-search-live @endif>
                    @foreach ($searchKeep as [$key, $value])
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endforeach
                    {{-- X clears and closes the search, the magnifier searches (points 104, 106; Field with symbol). --}}
                    <div class="field-addon">
                        <input type="search" class="input-small" name="q" value="{{ $searchTerm }}" placeholder="{{ $search }}" aria-label="{{ $search }}" autocomplete="off" enterkeyhint="search">
                        <span class="addon-end">
                            <button type="button" class="control-button addon-button" data-window-search-clear aria-label="{{ __('app.window.search_clear') }}" data-tooltip="{{ __('app.window.search_clear') }}" @if ($searchTerm === '' && ! $searchSet) hidden @endif><x-icon name="x" /></button>
                            <button type="submit" class="control-button addon-button" aria-label="{{ __('app.window.search_run') }}" data-tooltip="{{ __('app.window.search_run') }}"><x-icon name="search" /></button>
                        </span>
                    </div>
                    @if (isset($searchOptions) && $searchOptions->hasActualContent())
                        <button type="button" class="icon-button win-search-toggle @if ($searchCount > 0) is-active @endif" data-window-search-toggle aria-expanded="{{ $searchCount > 0 ? 'true' : 'false' }}" aria-label="{{ __('app.window.search_options') }}" data-tooltip="{{ __('app.window.search_options') }}"><x-icon name="chevron-down" /><span class="badge" data-window-search-count @if ($searchCount === 0) hidden @endif>{{ $searchCount }}</span></button>
                    @endif
                </form>
            @endif
            <div class="win-actions">
                {{ $actions ?? '' }}
                {{-- The module's ⋯ menu (points 71, 73, A200): the framework's "Auf Home anheften" (A351, not on Home itself),
                     then the module's own entries (slot "menu"). Nothing to show, no button. --}}
                @php
                    $hasMenu = isset($menu) && $menu->hasActualContent();
                    $pinHome = $kind !== 'dashboard' && auth()->check() && Route::has('dashboard.pins.store');
                @endphp
                @if ($hasMenu || $pinHome)
                    <x-more-menu class="win-menu">
                        @if ($pinHome)
                            <button type="button" class="menu-item" data-pin-home="{{ route('dashboard.pins.store') }}" data-pin-icon="{{ $icon }}" data-failed="{{ __('dashboard.failed') }}"><x-icon name="pin" /><span>{{ __('dashboard.pin') }}</span></button>
                            @if ($hasMenu)<hr class="menu-separator">@endif
                        @endif
                        @if ($hasMenu){{ $menu }}@endif
                    </x-more-menu>
                @endif
            </div>
        </div>
        @if ($search && isset($searchOptions) && $searchOptions->hasActualContent())
            {{-- Points 4-7, 187: the advanced search opens as one card right under the search row (the chevron becomes its
                 tab); its fields belong to the search above (JS). --}}
            <div class="win-search-area" data-window-search-area @if ($searchCount === 0) hidden @endif>
                {{ $searchOptions }}
                {{-- 187.4: the footer inside the card – a quiet "Zurücksetzen" left, "Suchen" right (the magnifier in the field stays). --}}
                <div class="win-search-actions">
                    <button type="button" class="button-link is-quiet" data-window-search-clear>{{ __('app.window.search_reset_short') }}</button>
                    <button type="button" class="button-small" data-window-search-run><x-icon name="search" />{{ __('app.window.search_run') }}</button>
                </div>
            </div>
        @endif
        <div class="win-body" @if ($fit) data-window-fit="{{ $fit }}" @endif @if ($content) data-window-content="{{ $content }}" @endif>{{ $slot }}</div>
    </div>
    {{-- The drag edge on the right (point 44): drag, arrow keys, double-click = default width. --}}
    <div class="win-edge" data-window-edge role="separator" aria-orientation="vertical" tabindex="0" aria-label="{{ __('app.window.resize') }}" title="{{ __('app.window.resize_hint') }}"></div>
</section>
