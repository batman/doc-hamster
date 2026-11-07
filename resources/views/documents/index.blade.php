@extends('layouts.app')

@section('title', $open?->title ?? __('documents.title'))

@section('main_class', 'page-windows')

@section('content')
    @php $openFolder = $folder !== null ? collect($folders)->pluck('folder')->firstWhere('id', $folder) : null; @endphp
    <div class="windows" data-windows>
    {{-- Folders on the window system (PO, 2026-11-07; FRAMEWORK.md: the main menu has no sub-items): Belege lists the top folders
         and the Papierkorb as rows, a folder opens as a window on the right with its subfolders and receipts. --}}
    <x-window :title="$openFolder?->name ?? __('documents.title')" :icon="$openFolder?->iconName() ?? 'receipt'"
              :url="route('documents.index', request()->except('page'))" :close="$openFolder ? route('documents.index') : null"
              :upload="$openFolder ? 'folder='.$openFolder->id : null" :class="$openFolder?->colorClass()">
        <x-slot:actions>
            <div class="toolbar">
                @if ($tags->isNotEmpty())
                    {{-- Ticks only collect; "Anwenden" filters, in the browser when the page holds every receipt, else on the server. --}}
                    <form class="dropdown" data-kind="filter" data-complete="{{ $complete ? '1' : '0' }}" method="get" action="{{ route('documents.index') }}">
                        @if ($month)
                            <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                        @endif
                        @if ($openFolder)
                            <input type="hidden" name="folder" value="{{ $openFolder->id }}">
                        @endif
                        <button type="button" class="icon-button @if ($selected !== []) is-active @endif" data-tooltip="{{ __('documents.filter') }}" aria-label="{{ __('documents.filter') }}" aria-haspopup="true" aria-expanded="false">
                            <span data-icon="off" @if ($selected !== []) hidden @endif><x-icon name="filter" /></span>
                            <span data-icon="on" @if ($selected === []) hidden @endif><x-icon name="filter-check" /></span>
                            <span class="badge" @if ($selected === []) hidden @endif>{{ count($selected) }}</span>
                        </button>
                        <div class="dropdown-panel" hidden>
                            <p class="dropdown-title">{{ __('tags.filter') }}</p>
                            <div class="tags dropdown-tags">
                                @foreach ($tags as $tag)
                                    <label class="tag-choice"><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selected, true))><span class="tag tag-{{ $tag->id }}">{{ $tag->name }}</span></label>
                                @endforeach
                            </div>
                            <div class="dialog-actions">
                                <button type="button" class="button-quiet" data-reset>{{ __('documents.reset') }}</button>
                                <button type="submit" data-apply>{{ __('documents.apply') }}</button>
                            </div>
                        </div>
                    </form>
                @endif
                <form class="dropdown" data-kind="view" method="post" action="{{ route('documents.view') }}">
                    @csrf
                    <button type="button" class="icon-button" data-tooltip="{{ __('documents.view') }}" aria-label="{{ __('documents.view') }}" aria-haspopup="true" aria-expanded="false"><x-icon :name="$views[$view]" /></button>
                    <div class="dropdown-panel" role="menu" hidden>
                        <p class="dropdown-title">{{ __('documents.view') }}</p>
                        @foreach ($views as $name => $icon)
                            <button type="submit" name="view" value="{{ $name }}" class="menu-item" role="menuitemradio" aria-checked="{{ $name === $view ? 'true' : 'false' }}"><x-icon :name="$icon" /><span>{{ __('documents.views.'.$name) }}</span></button>
                        @endforeach
                    </div>
                </form>
                @can('documents.edit')
                    <x-more-menu>
                        @if (! $openFolder)
                            <a class="menu-item" href="{{ route('folders.index', ['new' => 1]) }}" data-window><x-icon name="folder-plus" /><span>{{ __('folders.add') }}</span></a>
                        @else
                            @if ($openFolder->depth() < \App\Domain\Documents\Folder::MAX_DEPTH)
                                <a class="menu-item" href="{{ route('folders.index', ['parent' => $openFolder->id]) }}" data-window><x-icon name="folder-plus" /><span>{{ __('folders.add_sub_short') }}</span></a>
                            @endif
                            <a class="menu-item" href="{{ route('folders.index', ['folder' => $openFolder->id]) }}" data-window><x-icon name="pencil" /><span>{{ __('folders.edit') }}</span></a>
                        @endif
                    </x-more-menu>
                @endcan
            </div>
        </x-slot:actions>

        @if ($folderRows !== [])
            {{-- Sortable rows (FRAMEWORK.md "Sortable list"); app.js saves the order on list:sorted. The Papierkorb only on top. --}}
            @php $sortable = Gate::allows('documents.edit') && count($folderRows) > 1; @endphp
            <ul class="pick-list folder-rows" @if ($sortable) data-sortable data-folder-order="{{ route('folders.order') }}" data-parent="{{ $openFolder?->id }}" data-order-failed="{{ __('folders.order_invalid') }}" @endif>
                @foreach ($folderRows as $row)
                    @if ($row['folder'] === null)
                        <li class="pick-row is-static" data-id="trash">
                            @if ($sortable)<button type="button" class="pick-handle" aria-label="{{ __('folders.drag', ['name' => __('folders.trash')]) }}"><x-icon name="grip-vertical" /></button>@endif
                            <x-icon name="trash" />
                            <span><strong><a href="{{ route('documents.trash') }}" data-window>{{ __('folders.trash') }}</a></strong><small>{{ trans_choice('folders.used', $trashed, ['count' => $trashed]) }}</small></span>
                        </li>
                    @else
                        <li class="pick-row is-static {{ $row['folder']->colorClass() }}" data-id="{{ $row['folder']->id }}">
                            @if ($sortable)<button type="button" class="pick-handle" aria-label="{{ __('folders.drag', ['name' => $row['folder']->name]) }}"><x-icon name="grip-vertical" /></button>@endif
                            <x-icon :name="$row['folder']->iconName()" />
                            <span><strong><a href="{{ route('documents.index', ['folder' => $row['folder']->id]) }}" data-window>{{ $row['folder']->name }}</a></strong><small>{{ trans_choice('folders.used', $row['count'], ['count' => $row['count']]) }}</small></span>
                        </li>
                    @endif
                @endforeach
            </ul>
        @endif

        @if (session('status'))
            <p class="check-ok" role="status">{{ session('status') }}</p>
        @endif

        @if ($documents->isEmpty() && $selected === [] && $view !== 'calendar')
            <div class="empty-state"><p class="muted">{{ __('documents.empty') }}</p></div>
        @else
            @include('documents.views.'.(in_array($view, ['small', 'large'], true) ? 'tiles' : $view))
            <p class="muted" data-no-match @unless ($documents->isEmpty() && $selected !== []) hidden @endunless>{{ __('tags.no_match') }}</p>
        @endif
    </x-window>

    @if ($open)
        @include('documents._detail', ['document' => $open])
    @endif
    </div>
@endsection
