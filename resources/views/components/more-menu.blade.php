{{--
    The "⋯" menu (Go 2026-11-07, point 27; docs/design/FRAMEWORK.md). Entries are .menu-item links or
    buttons with icon and text; a link with data-window opens its target as a window on the right.
    <x-more-menu>
        <a class="menu-item" href="…" data-window><x-icon name="pencil" /><span>Bearbeiten</span></a>
        <hr class="menu-separator">
        <button type="button" class="menu-item menu-danger" data-dialog-open="…"><x-icon name="trash" /><span>Löschen</span></button>
    </x-more-menu>
    Entries never wrap; the menu is as wide as its longest entry. <hr class="menu-separator"> draws a line
    between groups, .menu-danger marks a destructive entry (red, last).
--}}
@props(['label' => __('app.more')])
<div {{ $attributes->class('dropdown more-menu') }}>
    <button type="button" class="icon-button" aria-label="{{ $label }}" data-tooltip="{{ $label }}" aria-haspopup="true" aria-expanded="false"><x-icon name="dots" /></button>
    <div class="dropdown-panel" role="menu" hidden>{{ $slot }}</div>
</div>
