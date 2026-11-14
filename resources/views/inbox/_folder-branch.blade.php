{{-- One level of the folder tree in "Ablegen in …" (A360); a branch opens with its chevron. --}}
<ul class="menu-tree" @unless ($open) hidden @endunless>
    @foreach ($byParent->get($parent, []) as $row)
        @php $folder = $row['folder']; $children = $byParent->has($folder->id); @endphp
        <li>
            <div class="menu-tree-row">
                @if ($children)
                    <button type="button" class="control-button menu-tree-toggle" aria-expanded="false" aria-label="{{ __('inbox.expand') }}: {{ $folder->name }}" data-tooltip="{{ __('inbox.expand') }}" data-name="{{ $folder->name }}"><x-icon name="chevron-down" /></button>
                @else
                    <span class="menu-tree-toggle" aria-hidden="true"></span>
                @endif
                <button type="button" class="menu-item" data-file-into="{{ $folder->id }}"><x-icon :name="$folder->iconName()" /><span>{{ $folder->name }}</span></button>
            </div>
            @if ($children)
                @include('inbox._folder-branch', ['parent' => $folder->id, 'open' => false])
            @endif
        </li>
    @endforeach
</ul>
