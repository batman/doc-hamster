@extends('layouts.app')

@section('title', $open?->title ?? __('documents.title'))

@section('main_class', 'page-windows')

@section('content')
    @php $openFolder = $folder !== null ? collect($folders)->pluck('folder')->firstWhere('id', $folder) : null; @endphp
    <div class="windows" data-windows>
    {{-- Folders on the window system (PO, 2026-11-07; FRAMEWORK.md: the main menu has no sub-items): Dokumente lists the top folders
         and the Papierkorb as rows, a folder opens as a window on the right with its subfolders and receipts. --}}
    <x-window :title="$openFolder?->name ?? __('documents.title')" kind="folder" :icon="$openFolder?->iconName() ?? 'file-text'"
              :url="route('documents.index', request()->except('page'))" :close="$openFolder ? route('documents.index') : route('home')"
              :upload="$openFolder ? 'folder='.$openFolder->id : null"
              :search="__('documents.search')" search-params="ocr all tags[] any by from to match read type" :search-count="count($filters)">
        {{-- The advanced search (points 4-7): text where, tags, period, status, file type; all in the address (point 7). --}}
        <x-slot name="searchOptions">
            @php
                $now = \Carbon\CarbonImmutable::now();
                $quick = [
                    'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
                    'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
                    'this_year' => [$now->startOfYear(), $now->endOfYear()],
                    'last_year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
                ];
            @endphp
            <div class="search-grid">
                <fieldset class="search-group">
                    <legend>{{ __('documents.adv.text') }}</legend>
                    <label class="check"><input type="checkbox" name="ocr" value="1" @checked($ocr)> {{ __('documents.adv.ocr') }}</label>
                    <label class="check"><input type="checkbox" name="all" value="1" @checked($all)> {{ __('documents.search_all') }}</label>
                </fieldset>
                <fieldset class="search-group">
                    <legend>{{ __('documents.adv.period') }}</legend>
                    <div class="search-row">
                        <input type="date" class="input-small" name="from" value="{{ $period['from'] ?? '' }}" aria-label="{{ __('documents.adv.from') }}">
                        <span class="muted">–</span>
                        <input type="date" class="input-small" name="to" value="{{ $period['to'] ?? '' }}" aria-label="{{ __('documents.adv.to') }}">
                    </div>
                    <div class="search-row">
                        @foreach ($quick as $key => [$lo, $hi])
                            <button type="button" class="button-quiet button-small" data-period="{{ $lo->toDateString() }} {{ $hi->toDateString() }}">{{ __('documents.adv.quick.'.$key) }}</button>
                        @endforeach
                    </div>
                    <div class="search-row">
                        <label class="check"><input type="radio" name="by" value="doc" data-default @checked($by === 'doc')> {{ __('documents.dates.doc') }}</label>
                        <label class="check"><input type="radio" name="by" value="upload" @checked($by === 'upload')> {{ __('documents.dates.upload') }}</label>
                    </div>
                </fieldset>
                <fieldset class="search-group">
                    <legend>{{ __('documents.adv.status') }}</legend>
                    @foreach (['match' => $match, 'read' => $read] as $field => $value)
                        <label class="field-inline"><span>{{ __('documents.adv.'.$field) }}</span>
                            <select class="input-small" name="{{ $field }}">
                                <option value="">{{ __('documents.adv.any') }}</option>
                                <option value="yes" @selected($value === 'yes')>{{ __('documents.adv.yes') }}</option>
                                <option value="no" @selected($value === 'no')>{{ __('documents.adv.no') }}</option>
                            </select>
                        </label>
                    @endforeach
                    <label class="field-inline"><span>{{ __('documents.adv.type') }}</span>
                        <select class="input-small" name="type">
                            <option value="">{{ __('documents.adv.any') }}</option>
                            <option value="pdf" @selected($type === 'pdf')>PDF</option>
                            <option value="image" @selected($type === 'image')>{{ __('documents.adv.image') }}</option>
                        </select>
                    </label>
                </fieldset>
                @if ($tags->isNotEmpty())
                    <fieldset class="search-group search-tags">
                        <legend>{{ __('documents.adv.tags') }}</legend>
                        <div class="tags">
                            @foreach ($tags as $tag)
                                <label class="tag-choice"><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selected, true))><span class="tag tag-{{ $tag->id }}">{{ $tag->name }}</span></label>
                            @endforeach
                        </div>
                        <div class="search-row">
                            <label class="check"><input type="radio" name="any" value="" data-default @checked(! $anyTag)> {{ __('documents.adv.all_tags') }}</label>
                            <label class="check"><input type="radio" name="any" value="1" @checked($anyTag)> {{ __('documents.adv.any_tag') }}</label>
                        </div>
                    </fieldset>
                @endif
            </div>
        </x-slot>
        <x-slot:actions>
            <div class="toolbar">
                @php $back = route('documents.index', request()->except('page')); @endphp
                @if ($wide)
                    {{-- Timeline and calendar go by the receipt date or the upload date (point 108, A209); remembered and in ?date=. --}}
                    <form class="button-group" data-view-switch role="group" aria-label="{{ __('documents.dates.label') }}" method="post" action="{{ route('documents.view') }}">
                        @csrf
                        <input type="hidden" name="back" value="{{ $back }}">
                        @foreach (['doc', 'upload'] as $basis)
                            <button type="submit" name="date" value="{{ $basis }}" class="button-quiet button-small" aria-pressed="{{ $basis === $dates ? 'true' : 'false' }}">{{ __('documents.dates.'.$basis) }}</button>
                        @endforeach
                    </form>
                @else
                    {{-- The display modes of the folder view as a button group (points 16, 98); in a narrow window one dropdown button
                         (point 41). The view belongs to this window (?view=…, point 52); app.js reloads only this window and saves the choice. --}}
                    <form class="button-group win-wide-only" data-view-switch role="group" aria-label="{{ __('documents.view') }}" method="post" action="{{ route('documents.view') }}">
                        @csrf
                        <input type="hidden" name="back" value="{{ $back }}">
                        @foreach ($folderViews as $name)
                            <button type="submit" name="view" value="{{ $name }}" class="icon-button button-small" aria-pressed="{{ $name === $view ? 'true' : 'false' }}" aria-label="{{ __('documents.views.'.$name) }}" data-tooltip="{{ __('documents.views.'.$name) }}"><x-icon :name="$views[$name]" /></button>
                        @endforeach
                    </form>
                    <form class="dropdown win-narrow-only" data-view-switch method="post" action="{{ route('documents.view') }}">
                        @csrf
                        <input type="hidden" name="back" value="{{ $back }}">
                        <button type="button" class="icon-button" aria-label="{{ __('documents.view') }}: {{ __('documents.views.'.$view) }}" data-tooltip="{{ __('documents.view') }}" aria-haspopup="true" aria-expanded="false"><x-icon :name="$views[$view] ?? 'list'" /></button>
                        <div class="dropdown-panel" role="menu" hidden>
                            @foreach ($folderViews as $name)
                                <button type="submit" name="view" value="{{ $name }}" class="menu-item" role="menuitemradio" aria-checked="{{ $name === $view ? 'true' : 'false' }}"><x-icon :name="$views[$name]" /><span>{{ __('documents.views.'.$name) }}</span></button>
                            @endforeach
                        </div>
                    </form>
                @endif
                @php
                    // Timeline and calendar have no new document, no new folder and no folder actions (points 100, 101).
                    $canUpload = ! $wide && Gate::allows('documents.upload');
                    $canFolder = ! $wide && Gate::allows('documents.edit') && (! $openFolder || $openFolder->depth() < \App\Domain\Documents\Folder::MAX_DEPTH);
                    $canEditFolder = ! $wide && $openFolder && Gate::allows('documents.edit');
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
                    {{-- The three main views (point 98): the folder view in its last display mode, timeline, calendar. --}}
                    <form method="post" action="{{ route('documents.view') }}" data-view-switch>
                        @csrf
                        <input type="hidden" name="back" value="{{ $back }}">
                        @foreach ([[$folderMode, 'folder', 'folder'], ['timeline', 'timeline', 'timeline'], ['calendar', 'calendar', 'calendar']] as [$value, $icon, $label])
                            <button type="submit" name="view" value="{{ $value }}" class="menu-item" role="menuitemradio" aria-checked="{{ ($label === 'folder' ? ! $wide : $view === $value) ? 'true' : 'false' }}"><x-icon :name="$icon" /><span>{{ __('documents.main_views.'.$label) }}</span></button>
                        @endforeach
                    </form>
                    @if ($canEditFolder)
                        <hr class="menu-separator">
                    @elseif ($canUpload || $canFolder)
                        <hr class="menu-separator win-narrow-only">
                    @endif
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

        @if ($canUpload)
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
        @endif
        @if ($wide)
            {{-- Timeline and calendar take no dropped documents or files (points 100, 101); app.js looks for this mark. --}}
            <span data-no-drop hidden></span>
        @endif

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
                                {{-- Rename in place (pencil or a long press on the name, A161, A188-A190); also "Dokumente allgemein" (A18). --}}
                                <form class="folder-rename" method="post" action="{{ route('folders.update', $f->id) }}" data-folder-rename-form hidden>
                                    @csrf
                                    <input type="hidden" name="parent_id" value="{{ $f->parent_id }}">
                                    @if ($f->bank_matching)<input type="hidden" name="bank_matching" value="1">@endif
                                    <input class="input-small" name="name" value="{{ $f->name }}" maxlength="100" required aria-label="{{ __('folders.name') }}">
                                    <button type="submit" class="control-button" aria-label="{{ __('folders.save') }}" data-tooltip="{{ __('folders.save') }}"><x-icon name="check" /></button>
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

        @if ($searching)
            {{-- The result list (point 105): documents only, each with its folder; X closes it (point 106). --}}
            <div class="search-head" data-search-count="{{ count($filters) }}">
                <h2>{{ trans_choice('documents.search_found', $documents->count(), ['count' => $documents->count()]) }}</h2>
                <a class="control-button" href="{{ route('documents.index', request()->except(['q', 'ocr', 'all', 'tags', 'any', 'by', 'from', 'to', 'match', 'read', 'type', 'page'])) }}" data-window-search-clear aria-label="{{ __('app.window.search_reset') }}" data-tooltip="{{ __('app.window.search_reset') }}"><x-icon name="x" /></a>
            </div>
            {{-- Point 6: every active filter as a chip; its X removes just that one. --}}
            @php
                $chips = [];
                if (request()->filled('q')) { $chips[] = ['q', null, '"'.request('q').'"']; }
                foreach ($tags->whereIn('id', $selected) as $tag) { $chips[] = ['tags[]', $tag->id, '#'.$tag->name]; }
                if (isset($period['from'])) { $chips[] = ['from', null, __('documents.adv.from').' '.\Carbon\Carbon::parse($period['from'])->format('d.m.Y')]; }
                if (isset($period['to'])) { $chips[] = ['to', null, __('documents.adv.to').' '.\Carbon\Carbon::parse($period['to'])->format('d.m.Y')]; }
                if ($match !== '') { $chips[] = ['match', null, __('documents.adv.match').': '.__('documents.adv.'.$match)]; }
                if ($read !== '') { $chips[] = ['read', null, __('documents.adv.read').': '.__('documents.adv.'.$read)]; }
                if ($type !== '') { $chips[] = ['type', null, $type === 'pdf' ? 'PDF' : __('documents.adv.image')]; }
            @endphp
            @if ($chips !== [])
                <div class="filter-chips">
                    @foreach ($chips as [$name, $value, $label])
                        <span class="filter-chip">{{ $label }}<button type="button" class="control-button" data-window-search-drop="{{ $name }}" @if ($value !== null) data-value="{{ $value }}" @endif aria-label="{{ __('documents.adv.remove', ['name' => $label]) }}"><x-icon name="x" /></button></span>
                    @endforeach
                </div>
            @endif
        @endif
        @if ($documents->isEmpty() && $view !== 'calendar')
            {{-- A folder that only holds subfolders isn't "empty" (explorer, point 12). --}}
            @if ($folderRows === [] || $searching)
                <div class="empty-state"><p class="muted">{{ $searching ? __('documents.search_none') : __('documents.empty') }}</p></div>
            @endif
        @else
            @include('documents.views.'.(in_array($view, ['small', 'large'], true) ? 'tiles' : $view))
        @endif
    </x-window>

    @if ($open)
        @include('documents._detail', ['document' => $open])
    @endif
    </div>
@endsection
