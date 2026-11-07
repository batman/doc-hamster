{{-- One level of the folder menu under "Belege" (PO, 2026-11-07). $items: folders of this level in order, null = the Papierkorb (top level only).
     $children: folders grouped by parent id. $open: ids of the open folder and its parents. $edit: may manage folders (drag, "+"). --}}
<ul class="nav-folders" data-parent="{{ $parent ?? '' }}">
    @foreach ($items as $item)
        @if ($item === null)
            <li class="nav-folder" data-id="trash">
                <div class="nav-folder-row">
                    @if ($edit)<button type="button" class="nav-grip" data-grip aria-label="{{ __('folders.drag', ['name' => __('folders.trash')]) }}"><x-icon name="grip-vertical" /></button>@endif
                    <a class="nav-item nav-folder-link" href="{{ route('documents.trash') }}" @if (request()->routeIs('documents.trash')) aria-current="page" @endif><x-icon name="trash" /><span class="nav-text">{{ __('folders.trash') }}</span></a>
                </div>
            </li>
        @else
            @php
                $kids = $children->get($item->id, collect());
                $depth = $depth ?? 0;
            @endphp
            <li @class(['nav-folder', 'is-open' => in_array($item->id, $open, true)]) data-id="{{ $item->id }}">
                <div class="nav-folder-row">
                    @if ($edit)<button type="button" class="nav-grip" data-grip aria-label="{{ __('folders.drag', ['name' => $item->name]) }}"><x-icon name="grip-vertical" /></button>@endif
                    <a class="nav-item nav-folder-link {{ $item->colorClass() }}" href="{{ route('documents.index', ['folder' => $item->id]) }}" @if (request()->routeIs('documents.index') && request()->query('folder') === $item->id) aria-current="page" @endif><x-icon :name="$item->iconName()" /><span class="nav-text">{{ $item->name }}</span></a>
                    @if ($kids->isNotEmpty())
                        <button type="button" class="nav-folder-toggle" data-folder-toggle aria-expanded="{{ in_array($item->id, $open, true) ? 'true' : 'false' }}" aria-label="{{ $item->name }}"><x-icon name="chevron-down" /></button>
                    @endif
                    @if ($edit && $depth < \App\Domain\Documents\Folder::MAX_DEPTH)
                        <a class="nav-folder-add" href="{{ route('folders.index', ['parent' => $item->id]) }}" aria-label="{{ __('folders.add_sub', ['name' => $item->name]) }}" title="{{ __('folders.add_sub', ['name' => $item->name]) }}"><x-icon name="plus" /></a>
                    @endif
                </div>
                @if ($kids->isNotEmpty())
                    @include('folders._menu', ['items' => $kids->all(), 'parent' => $item->id, 'depth' => $depth + 1])
                @endif
            </li>
        @endif
    @endforeach
</ul>
