@extends('layouts.app')

@section('title', $open?->title ?? __('documents.title'))

@section('main_class', 'page-windows')

@section('content')
    @php $openFolder = $folder !== null ? collect($folders)->pluck('folder')->firstWhere('id', $folder) : null; @endphp
    <div class="windows" data-windows>
    {{-- Folders on the window system (PO, 2026-11-07; FRAMEWORK.md: the main menu has no sub-items): Dokumente lists the top folders
         and the Papierkorb as rows, a folder opens as a window on the right with its subfolders and receipts. --}}
    <x-window :title="$openFolder?->name ?? __('documents.title')" :icon="$openFolder?->iconName() ?? 'file-text'"
              :url="route('documents.index', request()->except('page'))" :close="$openFolder ? route('documents.index') : null"
              :upload="$openFolder ? 'folder='.$openFolder->id : null" :class="$openFolder?->colorClass()"
              :search="__('documents.search')">
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
                {{-- The views as a button group (point 16); in a narrow window one dropdown button (point 41). --}}
                <form class="button-group win-wide-only" role="group" aria-label="{{ __('documents.view') }}" method="post" action="{{ route('documents.view') }}">
                    @csrf
                    @foreach ($views as $name => $icon)
                        <button type="submit" name="view" value="{{ $name }}" class="icon-button button-small" aria-pressed="{{ $name === $view ? 'true' : 'false' }}" aria-label="{{ __('documents.views.'.$name) }}" data-tooltip="{{ __('documents.views.'.$name) }}"><x-icon :name="$icon" /></button>
                    @endforeach
                </form>
                <form class="dropdown win-narrow-only" method="post" action="{{ route('documents.view') }}">
                    @csrf
                    <button type="button" class="icon-button" aria-label="{{ __('documents.view') }}: {{ __('documents.views.'.$view) }}" data-tooltip="{{ __('documents.view') }}" aria-haspopup="true" aria-expanded="false"><x-icon :name="$views[$view] ?? 'list'" /></button>
                    <div class="dropdown-panel" role="menu" hidden>
                        @foreach ($views as $name => $icon)
                            <button type="submit" name="view" value="{{ $name }}" class="menu-item" role="menuitemradio" aria-checked="{{ $name === $view ? 'true' : 'false' }}"><x-icon :name="$icon" /><span>{{ __('documents.views.'.$name) }}</span></button>
                        @endforeach
                    </div>
                </form>
                @php
                    $canUpload = Gate::allows('documents.upload');
                    $canFolder = Gate::allows('documents.edit') && (! $openFolder || $openFolder->depth() < \App\Domain\Documents\Folder::MAX_DEPTH);
                    $canEditFolder = $openFolder && Gate::allows('documents.edit');
                    $addLabel = $openFolder ? __('folders.add_sub_short') : __('folders.add');
                    $addUrl = route('folders.index', $openFolder ? ['parent' => $openFolder->id] : ['new' => 1]);
                @endphp
                @if ($canUpload)
                    {{-- A text document written in the app, in this folder (point 21). --}}
                    <button type="button" class="icon-button win-wide-only" data-dialog-open="new-text-{{ $openFolder?->id ?? 'top' }}" aria-label="{{ __('documents.text.new') }}" data-tooltip="{{ __('documents.text.new') }}"><x-icon name="file-plus" /></button>
                @endif
                @if ($canFolder)
                    {{-- Folders are managed where they are shown, like an explorer (points 10-12). --}}
                    <a class="icon-button win-wide-only" href="{{ $addUrl }}" data-window aria-label="{{ $addLabel }}" data-tooltip="{{ $addLabel }}"><x-icon name="folder-plus" /></a>
                @endif
                @if ($canEditFolder || $canUpload || $canFolder)
                    {{-- In a narrow window the extra buttons become entries of the ⋯ menu (point 41). --}}
                    <x-more-menu class="{{ $canEditFolder ? '' : 'win-narrow-only' }}">
                        @if ($canUpload)
                            <button type="button" class="menu-item win-narrow-only" data-dialog-open="new-text-{{ $openFolder?->id ?? 'top' }}"><x-icon name="file-plus" /><span>{{ __('documents.text.new') }}</span></button>
                        @endif
                        @if ($canFolder)
                            <a class="menu-item win-narrow-only" href="{{ $addUrl }}" data-window><x-icon name="folder-plus" /><span>{{ $addLabel }}</span></a>
                        @endif
                        @if ($canEditFolder)
                            @if ($canUpload || $canFolder)
                                <hr class="menu-separator win-narrow-only">
                            @endif
                            <a class="menu-item" href="{{ route('folders.index', ['folder' => $openFolder->id]) }}" data-window><x-icon name="pencil" /><span>{{ __('folders.edit') }}</span></a>
                        @endif
                    </x-more-menu>
                @endif
            </div>
        </x-slot:actions>

        @can('documents.upload')
            @php $newText = 'new-text-'.($openFolder?->id ?? 'top'); @endphp
            <div class="dialog-backdrop" data-dialog="{{ $newText }}" hidden>
                <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $newText }}-title">
                    <h2 id="{{ $newText }}-title">{{ __('documents.text.new_title') }}</h2>
                    <form method="post" action="{{ route('documents.text.store') }}" data-text-create>
                        @csrf
                        <input type="hidden" name="folder" value="{{ $openFolder?->id }}">
                        <div class="field">
                            <label for="{{ $newText }}-name">{{ __('documents.name') }}</label>
                            <input id="{{ $newText }}-name" name="name" maxlength="250" required placeholder="{{ __('documents.text.name_placeholder') }}">
                        </div>
                        <fieldset class="text-kinds">
                            <legend>{{ __('documents.text.kind') }}</legend>
                            <label class="text-kind"><input type="radio" name="kind" value="plain" checked><span><strong>{{ __('documents.text.plain') }}</strong><small>{{ __('documents.text.plain_hint') }}</small></span></label>
                            <label class="text-kind"><input type="radio" name="kind" value="rich"><span><strong>{{ __('documents.text.rich') }}</strong><small>{{ __('documents.text.rich_hint') }}</small></span></label>
                        </fieldset>
                        <div class="dialog-actions">
                            <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                            <button type="submit">{{ __('documents.text.create') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan

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
                        @php $f = $row['folder']; @endphp
                        <li class="pick-row is-static {{ $f->colorClass() }}" data-id="{{ $f->id }}">
                            @if ($sortable)<button type="button" class="pick-handle" aria-label="{{ __('folders.drag', ['name' => $f->name]) }}"><x-icon name="grip-vertical" /></button>@endif
                            <span class="icon-tile"><x-icon :name="$f->iconName()" /></span>
                            <span data-folder-name><strong><a href="{{ route('documents.index', ['folder' => $f->id]) }}" data-window>{{ $f->name }}</a></strong><small>{{ trans_choice('folders.used', $row['count'], ['count' => $row['count']]) }}</small></span>
                            @can('documents.edit')
                                {{-- Rename in place (app.js: "Umbenennen" in the row menu shows this form); also "Dokumente allgemein" (A18). --}}
                                @if ($f->exists)
                                    <form class="folder-rename" method="post" action="{{ route('folders.update', $f->id) }}" data-folder-rename-form hidden>
                                        @csrf
                                        <input type="hidden" name="parent_id" value="{{ $f->parent_id }}">
                                        @if ($f->bank_matching)<input type="hidden" name="bank_matching" value="1">@endif
                                        <input class="input-small" name="name" value="{{ $f->name }}" maxlength="100" required aria-label="{{ __('folders.name') }}">
                                        <button type="submit" class="icon-button" aria-label="{{ __('folders.save') }}" data-tooltip="{{ __('folders.save') }}"><x-icon name="check" /></button>
                                        <button type="button" class="icon-button" data-folder-rename-cancel aria-label="{{ __('folders.cancel') }}" data-tooltip="{{ __('folders.cancel') }}"><x-icon name="x" /></button>
                                    </form>
                                @endif
                                <x-more-menu :label="__('folders.actions', ['name' => $f->name])">
                                    <button type="button" class="menu-item" data-folder-rename><x-icon name="pencil" /><span>{{ __('folders.rename') }}</span></button>
                                    @unless ($f->is_default)
                                        <button type="button" class="menu-item" data-dialog-open="folder-move-{{ $f->id }}"><x-icon name="arrows-move" /><span>{{ __('folders.move') }}</span></button>
                                    @endunless
                                    <a class="menu-item" href="{{ route('folders.index', ['folder' => $f->id]) }}" data-window><x-icon name="settings" /><span>{{ __('folders.properties') }}</span></a>
                                    @unless ($f->is_default)
                                        <button type="button" class="menu-item" data-dialog-open="folder-delete-{{ $f->id }}"><x-icon name="trash" /><span>{{ __('folders.delete') }}</span></button>
                                    @endunless
                                </x-more-menu>
                            @endcan
                        </li>
                    @endif
                @endforeach
            </ul>

            @can('documents.edit')
                {{-- Move and delete dialogs of the rows; outside the sortable list, so a click in them never drags a row. --}}
                @foreach ($folderRows as $row)
                    @continue($row['folder'] === null || $row['folder']->is_default)
                    @php
                        $f = $row['folder'];
                        // The folder itself and what lies below it can't be the target (the server checks the depth too).
                        $blocked = [];
                        $depth = null;
                        foreach ($folders as $other) {
                            if ($depth !== null && $other['depth'] <= $depth) {
                                break;
                            }
                            if ($depth !== null || $other['folder']->id === $f->id) {
                                $depth ??= $other['depth'];
                                $blocked[] = $other['folder']->id;
                            }
                        }
                    @endphp
                    <div class="dialog-backdrop" data-dialog="folder-move-{{ $f->id }}" hidden>
                        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="folder-move-{{ $f->id }}-title">
                            <h2 id="folder-move-{{ $f->id }}-title">{{ __('folders.move_title', ['name' => $f->name]) }}</h2>
                            <form method="post" action="{{ route('folders.update', $f->id) }}">
                                @csrf
                                <input type="hidden" name="name" value="{{ $f->name }}">
                                @if ($f->bank_matching)<input type="hidden" name="bank_matching" value="1">@endif
                                <label for="folder-move-{{ $f->id }}-to">{{ __('folders.move_to') }}</label>
                                <select id="folder-move-{{ $f->id }}-to" name="parent_id">
                                    <option value="">{{ __('folders.top') }}</option>
                                    @foreach ($folders as $other)
                                        @unless (in_array($other['folder']->id, $blocked, true) || $other['depth'] >= \App\Domain\Documents\Folder::MAX_DEPTH)
                                            <option value="{{ $other['folder']->id }}" @selected($f->parent_id === $other['folder']->id)>{{ str_repeat('– ', $other['depth']) }}{{ $other['folder']->name }}</option>
                                        @endunless
                                    @endforeach
                                </select>
                                <div class="dialog-actions">
                                    <button type="button" class="button-quiet" data-dialog-close>{{ __('folders.cancel') }}</button>
                                    <button type="submit">{{ __('folders.move') }}</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="dialog-backdrop" data-dialog="folder-delete-{{ $f->id }}" hidden>
                        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="folder-delete-{{ $f->id }}-title">
                            <h2 id="folder-delete-{{ $f->id }}-title">{{ __('folders.delete_title') }}</h2>
                            <p>{{ __('folders.delete_text', ['name' => $f->name]) }}</p>
                            @can('documents.trash')
                                <p>{{ __('folders.delete_content_text') }}</p>
                            @endcan
                            <div class="dialog-actions">
                                <button type="button" class="button-quiet" data-dialog-close>{{ __('folders.cancel') }}</button>
                                <form method="post" action="{{ route('folders.destroy', $f->id) }}">
                                    @csrf
                                    <button type="submit" class="button-quiet">{{ __('folders.delete_keep') }}</button>
                                </form>
                                @can('documents.trash')
                                    <form method="post" action="{{ route('folders.destroy', $f->id) }}">
                                        @csrf
                                        <input type="hidden" name="with_content" value="1">
                                        <button type="submit" class="button-danger">{{ __('folders.delete_content') }}</button>
                                    </form>
                                @endcan
                            </div>
                        </div>
                    </div>
                @endforeach
            @endcan
        @endif

        @if (session('status'))
            <p class="check-ok" role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif

        @if ($documents->isEmpty() && $selected === [] && $view !== 'calendar')
            {{-- A folder that only holds subfolders isn't "empty" (explorer, point 12). --}}
            @if ($folderRows === [] || request()->filled('q'))
                <div class="empty-state"><p class="muted">{{ __('documents.empty') }}</p></div>
            @endif
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
