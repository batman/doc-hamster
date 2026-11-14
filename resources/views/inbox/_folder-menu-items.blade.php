{{-- The entries of the Ordner-Menü (A360): the folders filed into last ($recent), below the whole tree ($folders).
     Used by the row menus' template (inbox/_folder-menu) and the detail's "Verschieben …" dialog (A363). --}}
@php
    $byParent = collect($folders)->groupBy(static fn (array $row): string => $row['folder']->parent_id ?? '');
    $byId = collect($folders)->keyBy(static fn (array $row): string => $row['folder']->id);
@endphp
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
