{{-- "Ablegen in …" (A360): the content of every row's folder menu, once per window; inbox.js copies it into the panel the
     first time a row's folder button opens it. On top the folders this person filed into last, below the whole tree
     (Ordner-Menü, FRAMEWORK.md). --}}
<template data-folder-menu-tpl data-expand="{{ __('inbox.expand') }}" data-collapse="{{ __('inbox.collapse') }}">
    @include('inbox._folder-menu-items')
</template>
