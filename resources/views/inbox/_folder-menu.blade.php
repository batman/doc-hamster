{{-- "Ablegen in …" (A360): the content of every row's folder menu, once per window; inbox.js copies it into the panel the
     first time a row's folder button opens it. On top the folders this person filed into last, below the whole tree
     (Ordner-Menü, FRAMEWORK.md). --}}
@php
    $byParent = collect($folders)->groupBy(static fn (array $row): string => $row['folder']->parent_id ?? '');
    $byId = collect($folders)->keyBy(static fn (array $row): string => $row['folder']->id);
@endphp
<template data-folder-menu-tpl data-expand="{{ __('inbox.expand') }}" data-collapse="{{ __('inbox.collapse') }}">
    @if ($recent !== [])
        <p class="dropdown-title">{{ __('inbox.recent') }}</p>
        @foreach ($recent as $id)
            @php $folder = $byId->get($id)['folder'] ?? null; @endphp
            @if ($folder !== null)
                <button type="button" class="menu-item" data-file-into="{{ $folder->id }}"><x-icon :name="$folder->iconName()" /><span>{{ $folder->name }}</span></button>
            @endif
        @endforeach
        <hr class="menu-separator">
    @endif
    <p class="dropdown-title">{{ __('inbox.all_folders') }}</p>
    @include('inbox._folder-branch', ['parent' => '', 'open' => true])
</template>
