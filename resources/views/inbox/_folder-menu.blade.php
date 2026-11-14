{{-- "Ablegen in …" (A360): the content of every folder menu of a window (row menus, the selection bar, A369/A370), once per
     window; core/folder-menu.js copies it into a panel each time it opens, so it always starts with only the path of
     $current open (A368). On top the folders this person filed into last, below the whole tree (Ordner-Menü, FRAMEWORK.md). --}}
<template data-folder-menu-tpl data-expand="{{ __('inbox.expand') }}" data-collapse="{{ __('inbox.collapse') }}">
    @include('inbox._folder-menu-items', ['current' => $current ?? null])
</template>
