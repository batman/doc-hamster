{{--
    Menu window ("Menü-Fenster", point 156, A331; docs/design/FRAMEWORK.md): an <x-window> whose body lists entries; each
    entry is a data-window link, so a click opens it as the next window on the right.
    <x-menu-window :title="__('settings.title')" icon="settings" :url="route('settings.index')" :close="route('home')"
                   view="settings" display="tiles" :entries="$entries" />
    entries: list of [href, icon, title, text (short description, optional), badge (Badges key, optional), count (optional)].
    view = the key the person's display choice is remembered under (cookie dh_menu_views, App\Http\MenuViews);
    display = the module's default, "list" or "tiles". The toolbar switch changes it for this window and remembers it.
    sections (point 21, A68): instead of entries, a list of [key, icon, current, entries] – each a foldable group (Klappgruppe,
    <x-fold-card compact>) with its name, the red sum of its counts and its entries; folded at first, the person's choice
    is remembered (cookie dh_folds, "<view>.<key>"), the group with the open page (current) is always open.
    The slot goes below the entries (e.g. a footer). Extra attributes go to the window section.
--}}
@props(['title', 'icon', 'url' => null, 'close' => null, 'view', 'display' => 'list', 'entries' => [], 'sections' => null])
@php
    $shown = \App\Http\MenuViews::display((string) $view, (string) $display);
    // Tiles want room, a list is narrow; it shrinks down to icons only before the layout makes it a strip.
    [$min, $optimal, $max] = $shown === 'tiles' ? ['16rem', '36rem', '56rem'] : ['12rem', '22rem', '28rem'];
@endphp
<x-window :title="$title" :icon="$icon" :url="$url" :close="$close" kind="menu" :min="$min" :optimal="$optimal" :max="$max" priority="1" {{ $attributes->class('win-menu-window') }}>
    <x-slot:actions>
        <div class="switcher" data-switch-static role="group" aria-label="{{ __('app.menu_window.display') }}">
            @foreach (['list' => 'list', 'tiles' => 'layout-grid'] as $mode => $modeIcon)
                <button type="button" data-menu-display="{{ $mode }}" aria-pressed="{{ $mode === $shown ? 'true' : 'false' }}" aria-label="{{ __('app.menu_window.'.$mode) }}" data-tooltip="{{ __('app.menu_window.'.$mode) }}"><x-icon :name="$modeIcon" /></button>
            @endforeach
        </div>
    </x-slot:actions>
    @if ($sections === null)
        @include('components._menu-entries', ['entries' => $entries, 'view' => $view, 'shown' => $shown])
    @else
        @foreach ($sections as $section)
            @php
                $keys = collect($section['entries'])->pluck('badge')->filter()->unique()->values()->all();
                $sum = collect($section['entries'])->unique('badge')->sum(static fn (array $e): int => (int) ($e['count'] ?? 0));
            @endphp
            <x-fold-card class="menu-section" :fold="$view.'.'.$section['key']" :title="__('app.menu_sections.'.$section['key'])" :icon="$section['icon']" compact folded :open="$section['current']">
                @if ($keys !== [])
                    <x-slot:action><span class="badge badge-alert" data-badge-sum="{{ implode(' ', $keys) }}" @if ($sum === 0) hidden @endif>{{ $sum > 99 ? '99+' : $sum }}</span></x-slot:action>
                @endif
                @include('components._menu-entries', ['entries' => $section['entries'], 'view' => $view, 'shown' => $shown])
            </x-fold-card>
        @endforeach
    @endif
    {{ $slot }}
</x-window>
