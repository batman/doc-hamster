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
        $groupEntries = [];
        foreach ($groups as $key => $each) {
            $groupEntries[] = ['href' => route('settings.group', $key), 'icon' => $each['icon'], 'title' => __('settings.groups.'.$key), 'text' => __('settings.group_texts.'.$key), ...$badge($key)];
        }
        $itemEntries = [];
        foreach ($current['items'] ?? [] as $each) {
            $itemEntries[] = ['href' => route($each['route']), 'icon' => $each['icon'], 'title' => __('settings.links.'.$each['key'].'.title'), 'text' => __('settings.links.'.$each['key'].'.text'), ...$badge($each['key'])];
        }
        $itemTitle = $entry !== null ? __('settings.links.'.$entry['key'].'.title') : __('settings.title');
        $itemIcon = $entry['icon'] ?? ($current['icon'] ?? 'settings');
        $itemUrl = $entry !== null ? route($entry['route']) : $groupUrl;
        $pageTitle = html_entity_decode(trim($__env->yieldContent('page_title')), ENT_QUOTES | ENT_HTML5);
        // The window left of the page marks the row that opened it (opener row): the entry, else the group.
        $pageOpener = $hasPane ? null : ($withEntries ? $itemUrl : $groupUrl);
        $pageClose = $hasPane ? $itemUrl : $groupUrl;
    @endphp
    <div class="windows" data-windows>
        <x-menu-window :title="__('settings.title')" icon="settings" :url="route('settings.index')" :close="route('home')" view="settings" display="tiles" :entries="$groupEntries">
            @yield('nav_footer')
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
            <x-window :title="$pageTitle !== '' ? $pageTitle : $itemTitle" :icon="$itemIcon" kind="settings-page" :close="$pageClose" min="28rem" optimal="44rem" max="72rem" priority="2" class="win-page" :data-window-opener="$pageOpener">
                @yield('page')
            </x-window>
        @endif
    </div>
@endsection
