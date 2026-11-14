{{-- One level of the Ordner-Menü's tree (A360, point 195): the chevron (right = closed, down = open) toggles the branch,
     the name files; leaves have an empty slot instead, so the names line up. Branches on $openPath start open (A368). --}}
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
                <button type="button" class="menu-item" data-file-into="{{ $folder->id }}" @if ($folder->id === $current) aria-current="true" @endif><x-icon name="folder" /><span>{{ $folder->name }}</span></button>
            </div>
            @if ($children)
                @include('inbox._folder-branch', ['parent' => $folder->id, 'open' => $isOpen])
            @endif
        </li>
    @endforeach
</ul>
