{{--
    One window of the window system (Go 2026-11-07, points 22 and 24, see docs/design/FRAMEWORK.md).
    A module only fills the body; title, icon, close, strip, history and animation come from the framework.
    Props: title, icon (Tabler name), url (this window's address, default the current one),
    close (where the X leads without JavaScript; null = the window can't be closed), slot "actions" for the head,
    upload (where an upload belongs while this window is active, e.g. "folder=01H…"; sent as extra form fields),
    min (the window's minimum width when it shares the room, e.g. "40rem"; default 30rem).
    On phones the actions move into a "⋯" menu, so the title keeps its room.
--}}
@props(['title', 'icon' => 'file-text', 'url' => null, 'close' => null, 'upload' => null, 'min' => null])
@php($url ??= request()->fullUrl())
<section {{ $attributes->class('win') }} data-window data-window-url="{{ $url }}" data-window-title="{{ $title }}" @if ($upload) data-window-upload="{{ $upload }}" @endif @if ($min) data-window-min="{{ $min }}" @endif aria-label="{{ $title }}">
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
            <h1 class="win-title">{{ $title }}</h1>
            @isset($actions)
                <div class="win-actions" data-win-actions>{{ $actions }}</div>
                <x-more-menu class="win-more" data-win-more />
            @endisset
            <button type="button" class="icon-button win-pin" data-window-pin aria-pressed="false" aria-label="{{ __('app.window.pin') }}" data-tooltip="{{ __('app.window.pin') }}" data-label-pin="{{ __('app.window.pin') }}" data-label-unpin="{{ __('app.window.unpin') }}"><x-icon name="pin" /><x-icon name="pin-filled" /></button>
            @if ($close)
                <a class="icon-button win-close" href="{{ $close }}" data-window-close aria-label="{{ __('app.window.close') }}" data-tooltip="{{ __('app.window.close') }}"><x-icon name="x" /></a>
            @endif
        </header>
        <div class="win-body">{{ $slot }}</div>
    </div>
</section>
