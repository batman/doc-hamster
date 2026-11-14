{{--
    Menu window ("Menü-Fenster", point 156, A331; docs/design/FRAMEWORK.md): an <x-window> whose body lists entries; each
    entry is a data-window link, so a click opens it as the next window on the right.
    <x-menu-window :title="__('settings.title')" icon="settings" :url="route('settings.index')" :close="route('home')"
                   view="settings" display="tiles" :entries="$entries" />
    entries: list of [href, icon, title, text (short description, optional), badge (Badges key, optional), count (optional)].
    view = the key the person's display choice is remembered under (cookie dh_menu_views, App\Http\MenuViews);
    display = the module's default, "list" or "tiles". The toolbar switch changes it for this window and remembers it.
    The slot goes below the entries (e.g. a footer). Extra attributes go to the window section.
--}}
@props(['title', 'icon', 'url' => null, 'close' => null, 'view', 'display' => 'list', 'entries' => []])
@php
    $shown = \App\Http\MenuViews::display((string) $view, (string) $display);
    // Tiles want room, a list is narrow; it shrinks down to icons only before the layout makes it a strip.
    [$min, $optimal, $max] = $shown === 'tiles' ? ['16rem', '36rem', '56rem'] : ['12rem', '22rem', '28rem'];
@endphp
<x-window :title="$title" :icon="$icon" :url="$url" :close="$close" kind="menu" :min="$min" :optimal="$optimal" :max="$max" priority="1" {{ $attributes->class('win-menu-window') }}>
    <x-slot:actions>
        <div class="button-group" role="group" aria-label="{{ __('app.menu_window.display') }}">
            @foreach (['list' => 'list', 'tiles' => 'layout-grid'] as $mode => $modeIcon)
                <button type="button" class="icon-button button-small" data-menu-display="{{ $mode }}" aria-pressed="{{ $mode === $shown ? 'true' : 'false' }}" aria-label="{{ __('app.menu_window.'.$mode) }}" data-tooltip="{{ __('app.menu_window.'.$mode) }}"><x-icon :name="$modeIcon" /></button>
            @endforeach
        </div>
    </x-slot:actions>
    <ul class="menu-entries is-{{ $shown }}" data-menu-entries="{{ $view }}">
        @foreach ($entries as $entry)
            <li>
                <a class="menu-entry tooltip-right" href="{{ $entry['href'] }}" data-window data-tooltip="{{ $entry['title'] }}">
                    <x-icon :name="$entry['icon']" />
                    <span class="menu-entry-text">
                        <span class="menu-entry-name">{{ $entry['title'] }}</span>
                        @if (($entry['text'] ?? '') !== '')
                            <small class="menu-entry-desc">{{ $entry['text'] }}</small>
                        @endif
                    </span>
                    @if (($entry['badge'] ?? null) !== null)
                        @php $count = (int) ($entry['count'] ?? 0); @endphp
                        <span class="badge badge-alert" data-badge="{{ $entry['badge'] }}" @if ($count === 0) hidden @endif>{{ $count > 9 ? '9+' : $count }}</span>
                    @endif
                    <span class="go"><x-icon name="chevron-right" /></span>
                </a>
            </li>
        @endforeach
    </ul>
    {{ $slot }}
</x-window>
