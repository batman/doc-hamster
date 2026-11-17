{{-- Einstellungen on the window system (points 156, A331; docs/design/FRAMEWORK.md "Menü-Fenster"): a row of windows –
     the groups (menu window, tiles), the entries of a group (menu window, list; skipped for a leaf group or one with a
     single entry), then the page. A page with @section('pane') (Tags) gets that pane as its own window before the page;
     @section('pane_actions') fills that window's toolbar, @section('page_title') names the page window (default: the
     menu entry). Strips, room, minimise and the phone back arrow all come from windows.js. --}}
@extends('layouts.app')

@section('main_class', 'page-windows')

@section('content')
    @php
        $groups = \App\Http\SettingsMenu::groups();
        [$group, $item] = \App\Http\SettingsMenu::current(request());
        $current = $group !== null ? ($groups[$group] ?? null) : null;
        $entry = $current !== null && $item !== null ? collect($current['items'])->firstWhere('key', $item) : null;
        $hasPage = trim($__env->yieldContent('page')) !== '';
        $hasPane = trim($__env->yieldContent('pane')) !== '';
        $withEntries = $current !== null && ! $current['leaf'] && count($current['items']) > 1;
        $groupUrl = $group !== null ? route('settings.group', $group) : route('settings.index');
        // The red counts lead down: group tile → entry row (point 3).
        $badges = \App\Http\Badges::counts(app(\App\Domain\Org\CurrentOrg::class));
        $badge = static function (string $key) use ($badges): array {
            $area = \App\Http\Badges::SETTINGS[$key] ?? null;

            return ['badge' => $area, 'count' => $area !== null ? ($badges[$area] ?? 0) : null];
        };
        // Point 21, A68: the groups in six foldable sections; the one with the open page starts open.
        $sections = \App\Http\SettingsMenu::sections($badge, $group);
        $itemEntries = [];
        foreach ($current['items'] ?? [] as $each) {
            $itemEntries[] = ['href' => route($each['route']), 'icon' => $each['icon'], 'title' => __('settings.links.'.$each['key'].'.title'), 'text' => __('settings.links.'.$each['key'].'.text'), ...$badge($each['key'])];
        }
        $itemTitle = $entry !== null ? __('settings.links.'.$entry['key'].'.title') : __('settings.title');
        $itemIcon = $entry['icon'] ?? ($current['icon'] ?? 'settings');
        $itemUrl = $entry !== null ? route($entry['route']) : $groupUrl;
        $pageTitle = html_entity_decode(trim($__env->yieldContent('page_title')), ENT_QUOTES | ENT_HTML5);
        // PO 2026-11-16: @section('page_rename', <POST url>) makes the page window's title renamable (Feld mit ✓).
        $pageRename = trim($__env->yieldContent('page_rename'));
        // The window left of the page marks the row that opened it (opener row): the entry, else the group.
        $pageOpener = $hasPane ? null : ($withEntries ? $itemUrl : $groupUrl);
        $pageClose = $hasPane ? $itemUrl : $groupUrl;
    @endphp
    <div class="windows" data-windows>
        <x-menu-window :title="__('settings.title')" icon="settings" :url="route('settings.index')" :close="route('home')" view="settings" display="tiles" :sections="$sections">
            @yield('nav_footer')
            @php $support = config('dochamster.support_url'); @endphp
            @if (is_string($support) && str_starts_with($support, 'https://'))
                {{-- Point 227: a quiet "Unterstützen" link below the groups, only once a support page is set. --}}
                <a class="list-entry support-link" href="{{ $support }}" target="_blank" rel="noopener noreferrer"><x-icon name="heart" /><span>{{ __('settings.support') }}</span></a>
            @endif
        </x-menu-window>

        @if ($withEntries)
            <x-menu-window :title="__('settings.groups.'.$group)" :icon="$current['icon']" :url="$groupUrl" :close="route('settings.index')" :view="'settings.'.$group" display="list" :entries="$itemEntries" />
        @endif

        @if ($hasPane)
            <x-window :title="$itemTitle" :icon="$itemIcon" kind="settings-pane" :url="$itemUrl" :close="$groupUrl" min="18rem" optimal="24rem" max="32rem" priority="1" :data-window-opener="$withEntries ? $itemUrl : $groupUrl">
                <x-slot:actions>@yield('pane_actions')</x-slot:actions>
                @yield('pane')
            </x-window>
        @endif

        @if ($hasPage)
            <x-window :title="$pageTitle !== '' ? $pageTitle : $itemTitle" :icon="$itemIcon" kind="settings-page" :close="$pageClose" min="28rem" optimal="44rem" max="72rem" priority="2" class="win-page" :data-window-opener="$pageOpener" :rename-url="$pageRename !== '' ? $pageRename : null">
                @yield('page')
            </x-window>
        @endif
    </div>
@endsection
