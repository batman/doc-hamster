@extends('layouts.app')

@section('title', $open?->title ?? __('documents.title'))

@section('main_class', 'page-windows')

@section('content')
    @php $openFolder = $folder !== null ? collect($folders)->pluck('folder')->firstWhere('id', $folder) : null; @endphp
    <div class="windows" data-windows>
    {{-- Folders on the window system (PO, 2026-11-07; FRAMEWORK.md: the main menu has no sub-items): Dokumente lists the top folders
         and the Papierkorb as rows, a folder opens as a window on the right with its subfolders and receipts. --}}
    <x-window :title="$openFolder?->name ?? __('documents.title')" kind="folder" :icon="$openFolder?->iconName() ?? 'file-text'"
              :url="route('documents.index', request()->except('page'))" :close="$openFolder ? route('documents.index') : null"
              :upload="$openFolder ? 'folder='.$openFolder->id : null"
              :search="__('documents.search')">
        <x-slot:actions>
            <div class="toolbar">
                @if ($tags->isNotEmpty())
                    {{-- Ticks only collect; "Anwenden" filters, in the browser when the page holds every receipt, else on the server. --}}
                    <form class="dropdown" data-kind="filter" data-complete="{{ $complete ? '1' : '0' }}" method="get" action="{{ route('documents.index') }}">
                        @if ($month)
                            <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                        @endif
                        @if (request()->has('view'))
                            <input type="hidden" name="view" value="{{ $view }}">
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
                {{-- The views as a button group (point 16); in a narrow window one dropdown button (point 41). The view belongs to
                     this window (?view=…, point 52); app.js reloads only this window and saves the choice as the default. --}}
                <form class="button-group win-wide-only" data-view-switch role="group" aria-label="{{ __('documents.view') }}" method="post" action="{{ route('documents.view') }}">
                    @csrf
                    <input type="hidden" name="back" value="{{ route('documents.index', request()->except('page')) }}">
                    @foreach ($views as $name => $icon)
                        <button type="submit" name="view" value="{{ $name }}" class="icon-button button-small" aria-pressed="{{ $name === $view ? 'true' : 'false' }}" aria-label="{{ __('documents.views.'.$name) }}" data-tooltip="{{ __('documents.views.'.$name) }}"><x-icon :name="$icon" /></button>
                    @endforeach
                </form>
                <form class="dropdown win-narrow-only" data-view-switch method="post" action="{{ route('documents.view') }}">
                    @csrf
                    <input type="hidden" name="back" value="{{ route('documents.index', request()->except('page')) }}">
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
            </div>
        </x-slot:actions>
        {{-- In a narrow window the extra buttons become entries of the module's ⋯ menu (point 41). --}}
        <x-slot:menu>
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
        </x-slot:menu>

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
            {{-- Folder rows (points 75-86): the whole row drags (no grip, point 82) – onto another folder row = into it, between rows =
                 new order (A153); documents dropped on a row move into it, on the Papierkorb into the trash. app.js does the dragging;
                 data-path/-depth/-height let it refuse a drop into itself or too deep (A138, A139). Row actions instead of ⋯ (A161-A166). --}}
            @php $canEdit = Gate::allows('documents.edit'); @endphp
            <ul class="pick-list folder-rows" @if ($canEdit) data-folder-order="{{ route('folders.order') }}" data-parent="{{ $openFolder?->id }}" @endif>
                @foreach ($folderRows as $row)
                    @if ($row['folder'] === null)
                        <li class="pick-row is-static folder-row {{ \App\Domain\Documents\Folder::rowClassFor($trashColor) }}" data-id="trash" data-folder-row data-drop-trash data-name="{{ $trashName }}">
                            <span class="row-icon"><x-icon name="trash" /></span>
                            <span data-folder-name><strong><a href="{{ route('documents.trash') }}" data-window>{{ $trashName }}</a></strong><small>{{ trans_choice('folders.used', $trashed, ['count' => $trashed]) }}</small></span>
                            @if ($canEdit)
                                <form class="folder-rename" method="post" action="{{ route('folders.trash') }}" data-folder-rename-form hidden>
                                    @csrf
                                    <input class="input-small" name="name" value="{{ $trashName }}" maxlength="100" required aria-label="{{ __('folders.name') }}">
                                    <button type="submit" class="control-button" aria-label="{{ __('folders.save') }}" data-tooltip="{{ __('folders.save') }}"><x-icon name="check" /></button>
                                    <button type="button" class="control-button" data-folder-rename-cancel aria-label="{{ __('folders.cancel') }}" data-tooltip="{{ __('folders.cancel') }}"><x-icon name="x" /></button>
                                </form>
                                <span class="row-actions">
                                    <button type="button" class="control-button" data-folder-rename aria-label="{{ __('folders.rename') }}: {{ $trashName }}" data-tooltip="{{ __('folders.rename') }}"><x-icon name="pencil" /></button>
                                    <a class="control-button" href="{{ route('folders.index', ['folder' => 'trash']) }}" data-window aria-label="{{ __('folders.properties') }}: {{ $trashName }}" data-tooltip="{{ __('folders.properties') }}"><x-icon name="settings" /></a>
                                </span>
                            @endif
                        </li>
                    @else
                        @php $f = $row['folder']; $place = $tree[$f->id] ?? ['depth' => 0, 'height' => 0, 'path' => [$f->id]]; @endphp
                        <li class="pick-row is-static folder-row {{ $f->rowClass() }}" data-id="{{ $f->id }}" data-folder-row data-name="{{ $f->name }}"
                            data-depth="{{ $place['depth'] }}" data-height="{{ $place['height'] }}" data-path="{{ implode(' ', $place['path']) }}" data-parent-id="{{ $f->parent_id }}"
                            data-upload-label="{{ __('documents.bulk.drop_upload', ['name' => $f->name]) }}"
                            @if ($canEdit && ! $f->is_default) data-drag-folder @endif>
                            <span class="row-icon"><x-icon :name="$f->iconName()" /></span>
                            <span data-folder-name><strong><a href="{{ route('documents.index', ['folder' => $f->id]) }}" data-window>{{ $f->name }}</a></strong><small>{{ trans_choice('folders.used', $row['count'], ['count' => $row['count']]) }}</small></span>
                            @if ($canEdit)
                                {{-- Rename in place (pencil, A161); also "Dokumente allgemein" (A18). --}}
                                <form class="folder-rename" method="post" action="{{ route('folders.update', $f->id) }}" data-folder-rename-form hidden>
                                    @csrf
                                    <input type="hidden" name="parent_id" value="{{ $f->parent_id }}">
                                    @if ($f->bank_matching)<input type="hidden" name="bank_matching" value="1">@endif
                                    <input class="input-small" name="name" value="{{ $f->name }}" maxlength="100" required aria-label="{{ __('folders.name') }}">
                                    <button type="submit" class="control-button" aria-label="{{ __('folders.save') }}" data-tooltip="{{ __('folders.save') }}"><x-icon name="check" /></button>
                                    <button type="button" class="control-button" data-folder-rename-cancel aria-label="{{ __('folders.cancel') }}" data-tooltip="{{ __('folders.cancel') }}"><x-icon name="x" /></button>
                                </form>
                                <span class="row-actions">
                                    <button type="button" class="control-button" data-folder-rename aria-label="{{ __('folders.rename') }}: {{ $f->name }}" data-tooltip="{{ __('folders.rename') }}"><x-icon name="pencil" /></button>
                                    <a class="control-button" href="{{ route('folders.index', ['folder' => $f->id]) }}" data-window aria-label="{{ __('folders.properties') }}: {{ $f->name }}" data-tooltip="{{ __('folders.properties') }}"><x-icon name="settings" /></a>
                                </span>
                            @endif
                        </li>
                    @endif
                @endforeach
            </ul>
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
