{{-- One level of the folder tree with checkboxes (role restriction, point 215.3): the Ordner-Menü's tree (FRAMEWORK.md,
     core/folder-menu.js toggles the branches), but each name is a checkbox `folders[]`. A ticked folder counts with all
     its subfolders. Branches with a ticked folder below start open. --}}
<ul class="menu-tree" @unless ($open) hidden @endunless>
    @foreach ($byParent->get($parent, []) as $row)
        @php
            $folder = $row['folder'];
            $children = $byParent->has($folder->id);
            $isOpen = in_array($folder->id, $openPath, true);
            $toggleLabel = $isOpen ? __('inbox.collapse') : __('inbox.expand');
        @endphp
        <li>
            <div class="menu-tree-row">
                @if ($children)
                    <button type="button" class="control-button menu-tree-toggle" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-label="{{ $toggleLabel }}: {{ $folder->name }}" data-tooltip="{{ $toggleLabel }}" data-name="{{ $folder->name }}"><x-icon name="chevron-right" /></button>
                @else
                    <span class="menu-tree-toggle" aria-hidden="true"></span>
                @endif
                <label class="menu-item"><input type="checkbox" name="folders[]" value="{{ $folder->id }}" @checked(in_array($folder->id, $ticked, true))><x-icon name="folder" /><span>{{ $folder->name }}</span></label>
            </div>
            @if ($children)
                @include('roles._folder-pick', ['parent' => $folder->id, 'open' => $isOpen])
            @endif
        </li>
    @endforeach
</ul>
