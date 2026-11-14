{{-- The entries of the Ordner-Menü (A360, point 195): the folders filed into last ($recent), below the whole tree ($folders)
     as a compact explorer tree. $current (optional): that folder's path is open and the folder marked (A368).
     Used by the window template (inbox/_folder-menu) and the detail's "Verschieben …" dialog (A363). --}}
@php
    $byParent = collect($folders)->groupBy(static fn (array $row): string => $row['folder']->parent_id ?? '');
    $byId = collect($folders)->keyBy(static fn (array $row): string => $row['folder']->id);
    $current ??= null;
    $openPath = [];
    $at = $current !== null ? ($byId->get($current)['folder']->parent_id ?? null) : null;
    while ($at !== null && ! in_array($at, $openPath, true)) {
        $openPath[] = $at;
        $at = $byId->get($at)['folder']->parent_id ?? null;
    }
@endphp
@if ($recent !== [])
    <p class="dropdown-title">{{ __('inbox.recent') }}</p>
    @foreach ($recent as $id)
        @php $folder = $byId->get($id)['folder'] ?? null; @endphp
        @if ($folder !== null)
            <button type="button" class="menu-item" data-file-into="{{ $folder->id }}"><x-icon name="folder" /><span>{{ $folder->name }}</span></button>
        @endif
    @endforeach
@endif
<p class="dropdown-title">{{ __('inbox.all_folders') }}</p>
@include('inbox._folder-branch', ['parent' => '', 'open' => true])
