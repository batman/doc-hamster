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
              :search="__('documents.search')" :search-params="implode(' ', ['ocr', 'all', 'tags[]', 'any', 'by', 'from', 'to', 'match', 'read', 'type', ...$propParams])" :search-count="count($filters)">
        {{-- The advanced search (points 4-7): text where, tags, period, status, file type; all in the address (point 7). --}}
        <x-slot name="searchOptions">
            {{-- Point 135 (A282-A285): on top the ticks, always visible; below four compact foldable sections, folded unless a
                 filter in them is set, each head with a summary of what is set (documents-search.js keeps it current). Button
                 groups as a choice instead of radios and "egal" selects. All fields keep their address names (point 7). --}}
            @php
                $choice = fn (string $name, string $value, array $options, ?string $label = null, bool $toggle = false) => view('documents._search-choice', compact('name', 'value', 'options', 'label', 'toggle'));
                $preset = $search->preset();
                $ranges = [];
                foreach (\App\Http\Documents\DocumentSearch::PRESETS as $key) { $ranges[$key] = implode(' ', \App\Http\Documents\DocumentSearch::presetRange($key)); }
                $day = fn (?string $date): string => $date ? \Carbon\Carbon::parse($date)->format('d.m.Y') : '';
                $periodSummary = $preset === null ? '' : ($preset === 'custom' ? trim($day($period['from'] ?? null).' – '.$day($period['to'] ?? null), ' –') : __('documents.adv.quick.'.$preset)).' · '.__('documents.dates.'.$by);
                $tagSummary = $selected === [] ? '' : __('documents.adv.tags_chosen', ['count' => count($selected)]).($anyTag ? ' · '.__('documents.adv.any_tag') : '');
                $statusSummary = implode(' · ', array_filter([
                    $match !== '' ? __('documents.adv.match').': '.__('documents.adv.'.$match) : null,
                    $read !== '' ? __('documents.adv.read').': '.__('documents.adv.'.$read) : null,
                    $type !== '' ? ($type === 'pdf' ? 'PDF' : __('documents.adv.image')) : null,
                ]));
                $propSummary = $properties->filter(fn ($p) => isset($propFilters[$p->id]))->pluck('name')->implode(', ');
            @endphp
            <div class="search-top">
                <label class="check"><input type="checkbox" name="ocr" value="1" @checked($ocr)> {{ __('documents.adv.ocr') }}</label>
                <label class="check"><input type="checkbox" name="all" value="1" @checked($all)> {{ __('documents.search_all') }}</label>
            </div>
            <div class="search-sections" data-doc-search data-chosen="{{ __('documents.adv.tags_chosen') }}">
                <x-fold-card fold="documents.search.period" :remember="false" compact :folded="$period === []" :title="__('documents.adv.period')" icon="calendar" :summary="$periodSummary" data-search-section="period">
                    <div class="search-line">
                        <span>{{ __('documents.adv.period') }}</span>
                        <span class="button-choice" data-choice data-choice-toggle data-period-choice>
                            <input type="hidden" value="{{ $preset ?? '' }}">
                            <span class="button-group" role="group" aria-label="{{ __('documents.adv.period') }}">
                                @foreach ($ranges as $presetKey => $presetRange)
                                    <button type="button" class="button-quiet button-small" data-value="{{ $presetKey }}" data-range="{{ $presetRange }}" aria-pressed="{{ $preset === $presetKey ? 'true' : 'false' }}">{{ __('documents.adv.quick.'.$presetKey) }}</button>
                                @endforeach
                                <button type="button" class="button-quiet button-small" data-value="custom" aria-pressed="{{ $preset === 'custom' ? 'true' : 'false' }}">{{ __('documents.adv.quick.custom') }}</button>
                            </span>
                        </span>
                    </div>
                    <div class="search-line" data-period-custom @if ($preset !== 'custom') hidden @endif>
                        <span>{{ __('documents.adv.from') }} – {{ __('documents.adv.to') }}</span>
                        <span class="search-row">
                            <input type="date" class="input-small" name="from" value="{{ $period['from'] ?? '' }}" aria-label="{{ __('documents.adv.from') }}">
                            <span class="muted">–</span>
                            <input type="date" class="input-small" name="to" value="{{ $period['to'] ?? '' }}" aria-label="{{ __('documents.adv.to') }}">
                        </span>
                    </div>
                    <div class="search-line">
                        <span>{{ __('documents.adv.basis') }}</span>
                        {!! $choice('by', $by === 'upload' ? 'upload' : '', ['' => __('documents.dates.doc'), 'upload' => __('documents.dates.upload')]) !!}
                    </div>
                </x-fold-card>
                @if ($tags->isNotEmpty())
                    <x-fold-card fold="documents.search.tags" :remember="false" compact :folded="$selected === []" :title="__('documents.adv.tags')" icon="tags" :summary="$tagSummary" data-search-section="tags">
                        <div class="tags">
                            @foreach ($tags as $tag)
                                <label class="tag-choice"><input type="checkbox" name="tags[]" value="{{ $tag->id }}" @checked(in_array($tag->id, $selected, true))><span class="tag tag-{{ $tag->id }}">{{ $tag->name }}</span></label>
                            @endforeach
                        </div>
                        <div class="search-line">
                            <span>{{ __('documents.adv.tags_mode') }}</span>
                            {!! $choice('any', $anyTag ? '1' : '', ['' => __('documents.adv.all_tags'), '1' => __('documents.adv.any_tag')]) !!}
                        </div>
                    </x-fold-card>
                @endif
                <x-fold-card fold="documents.search.status" :remember="false" compact :folded="$match === '' && $read === '' && $type === ''" :title="__('documents.adv.status')" icon="circle-check" :summary="$statusSummary" data-search-section="status">
                    @foreach (['match' => $match, 'read' => $read] as $field => $value)
                        <div class="search-line">
                            <span>{{ __('documents.adv.'.$field) }}</span>
                            {!! $choice($field, $value, ['' => __('documents.adv.any'), 'yes' => __('documents.adv.yes'), 'no' => __('documents.adv.no')], __('documents.adv.'.$field)) !!}
                        </div>
                    @endforeach
                    <div class="search-line">
                        <span>{{ __('documents.adv.type') }}</span>
                        {!! $choice('type', $type, ['' => __('documents.adv.any'), 'pdf' => 'PDF', 'image' => __('documents.adv.image')]) !!}
                    </div>
                </x-fold-card>
                @if ($properties->isNotEmpty())
                    {{-- Point 135 (A283): "+ Filter hinzufügen" picks a property, which shows one row with the input of its type and
                         "Alle | hat Wert | fehlt"; its X removes it. Rows of properties without a filter stay hidden. --}}
                    <x-fold-card fold="documents.search.props" :remember="false" compact :folded="$propFilters === []" :title="__('properties.search.legend')" icon="list-details" :summary="$propSummary" data-search-section="props">
                        @foreach ($properties as $property)
                            @php
                                $pf = $propFilters[$property->id] ?? [];
                                $pn = fn (string $field): string => \App\Domain\Properties\PropertySearch::name($property, $field);
                                $inputType = $property->type === 'date' ? 'date' : 'text';
                            @endphp
                            <div class="search-prop" data-search-prop="{{ $property->id }}" data-name="{{ $property->name }}" @if ($pf === []) hidden @endif>
                                <strong>{{ $property->name }}</strong>
                                <button type="button" class="control-button" data-prop-remove aria-label="{{ __('properties.search.remove') }}: {{ $property->name }}" data-tooltip="{{ __('properties.search.remove') }}"><x-icon name="x" /></button>
                                <div class="search-row">
                                    @if (in_array($property->type, ['amount', 'number', 'date'], true))
                                        <input type="{{ $inputType }}" class="input-small" name="{{ $pn('from') }}" value="{{ $pf['from'] ?? '' }}" @if ($inputType === 'text') inputmode="decimal" placeholder="{{ __('properties.search.from') }}" @endif aria-label="{{ $property->name }} {{ __('properties.search.from') }}">
                                        <span class="muted">–</span>
                                        <input type="{{ $inputType }}" class="input-small" name="{{ $pn('to') }}" value="{{ $pf['to'] ?? '' }}" @if ($inputType === 'text') inputmode="decimal" placeholder="{{ __('properties.search.to') }}" @endif aria-label="{{ $property->name }} {{ __('properties.search.to') }}">
                                    @elseif ($property->type === 'choice')
                                        <select class="input-small" name="{{ $pn('q') }}" aria-label="{{ $property->name }}">
                                            <option value="">{{ __('properties.search.any') }}</option>
                                            @foreach ($property->options ?? [] as $option)
                                                <option value="{{ $option }}" @selected(($pf['q'] ?? null) === $option)>{{ $option }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input type="text" class="input-small" name="{{ $pn('q') }}" value="{{ $pf['q'] ?? '' }}" placeholder="{{ __('properties.search.contains') }}" aria-label="{{ $property->name }} {{ __('properties.search.contains') }}">
                                    @endif
                                    {!! $choice($pn('has'), $pf['has'] ?? '', ['' => __('properties.search.any'), 'yes' => __('properties.search.has'), 'no' => __('properties.search.missing')]) !!}
                                </div>
                            </div>
                        @endforeach
                        <select class="input-small search-prop-add" data-prop-add aria-label="{{ __('properties.search.add') }}">
                            <option value="">{{ __('properties.search.add') }}</option>
                            @foreach ($properties as $property)
                                <option value="{{ $property->id }}" @disabled(isset($propFilters[$property->id]))>{{ $property->name }}</option>
                            @endforeach
                        </select>
                    </x-fold-card>
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
                    @if ($view === 'details' && $properties->isNotEmpty())
                        {{-- A244: property columns of the details view, remembered per person; this window reloads. --}}
                        <hr class="menu-separator">
                        <p class="dropdown-title">{{ __('properties.columns') }}</p>
                        <form method="post" action="{{ route('documents.columns') }}" data-doc-columns>
                            @csrf
                            @foreach ($properties as $property)
                                <button type="submit" name="toggle" value="{{ $property->id }}" class="menu-item" role="menuitemcheckbox" aria-checked="{{ in_array($property->id, $chosenColumns, true) ? 'true' : 'false' }}"><x-icon :name="$property->icon()" /><span>{{ $property->name }}</span></button>
                            @endforeach
                        </form>
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
                            <span data-folder-name><strong><a href="{{ route('documents.index', ['folder' => $f->id]) }}" data-window>{{ $f->name }}</a></strong><x-row-stats :folders="$row['folders']" :documents="$row['documents']" :total="$row['total']" /></span>
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
                <a class="control-button" href="{{ route('documents.index', request()->except(['q', 'ocr', 'all', 'tags', 'any', 'by', 'from', 'to', 'match', 'read', 'type', 'page', ...$propParams])) }}" data-window-search-clear aria-label="{{ __('app.window.search_reset') }}" data-tooltip="{{ __('app.window.search_reset') }}"><x-icon name="x" /></a>
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
                foreach (\App\Domain\Properties\PropertySearch::chips($propFilters, $properties) as [$chipName, $chipLabel]) { $chips[] = [$chipName, null, $chipLabel]; }
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
