@extends('layouts.app')

@section('title', __('home.title'))

@section('main_class', 'page-windows')

@section('content')
    <div class="windows" data-windows>
    {{-- The whole board is one module (points 71, 73): one window "Notizen"; its columns are plain content and never
         fold. It can be opened beside any module ("Notizen daneben öffnen" in every ⋯ menu) and several times. --}}
    <x-window :title="__('home.notes')" icon="note" kind="notes" :url="route('home', request()->only('tag'))" :close="route('home')" class="win-notes">
        <x-slot:actions>
            <div class="toolbar">
                <a class="icon-button" href="{{ route('notes.archive') }}" aria-label="{{ __('home.archive') }}" data-tooltip="{{ __('home.archive') }}"><x-icon name="archive" /></a>
                <button type="button" class="icon-button" data-dialog-open="note-new" data-note-column="{{ $columns->first()?->id }}" data-note-color="{{ $columns->first()?->newNoteColor() }}" aria-label="{{ __('home.new') }}" data-tooltip="{{ __('home.new') }}"><x-icon name="plus" /></button>
            </div>
        </x-slot:actions>

    {{-- Tag filter (A40): only notes with the chosen tag. --}}
    @if ($tags->isNotEmpty())
        <ul class="tags note-filter" aria-label="{{ __('home.filter') }}">
            <li><a class="tag note-filter-all @if ($filter === null) is-selected @endif" href="{{ route('home') }}">{{ __('home.filter_all') }}</a></li>
            @foreach ($tags as $tag)
                <li><a class="tag tag-{{ $tag->id }} @if ($filter?->id === $tag->id) is-selected @endif" href="{{ route('home', ['tag' => $tag->id]) }}">{{ $tag->name }}</a></li>
            @endforeach
        </ul>
    @endif

    <datalist id="note-documents">
        @foreach ($documents as $document)
            <option value="{{ $document->title }}"></option>
        @endforeach
    </datalist>

    <div class="dialog-backdrop note-backdrop" data-dialog="note-new" hidden>
        <div class="dialog note-dialog" role="dialog" aria-modal="true" aria-labelledby="note-new-title">
            <h2 id="note-new-title">{{ __('home.new') }}</h2>
            <form method="post" action="{{ route('notes.store') }}">
                @csrf
                <input type="hidden" name="column" value="{{ $columns->first()?->id }}" data-note-new-column>
                <fieldset class="note-fields">
                    @include('home._fields', ['note' => null, 'part' => 'main'])
                </fieldset>
                <details class="note-more" data-note-more>
                    <summary>{{ __('home.more_options') }}</summary>
                    <fieldset class="note-fields">
                        @include('home._fields', ['note' => null, 'part' => 'more'])
                    </fieldset>
                    <label class="note-lock-choice"><input type="checkbox" name="locked" value="1"> {{ __('home.locked') }}</label>
                </details>
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                    <button type="submit">{{ __('home.save') }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- The board (Go of 2026-11-08, like Trello): columns side by side, notes dragged by their head between and within them. --}}
    <div class="kanban" data-notes data-csrf="{{ csrf_token() }}" @if ($canShare) data-columns-order="{{ route('notes.columns.order') }}" @endif>
        @foreach ($columns as $column)
            @php($cards = $byColumn->get($column->id, collect()))
            {{-- Points 65-67, A100-A105, A115: everything a column does sits in its ⋮ menu; a double-click on free space adds a note. --}}
            <section @class(['kanban-col', 'has-color' => $column->color !== null, \App\Domain\Palette\PaletteColor::cssClass($column->color)]) data-column-id="{{ $column->id }}" data-sort="{{ $column->note_sort }}" aria-labelledby="column-name-{{ $column->id }}">
                <header class="kanban-head" @if ($canShare) draggable="true" data-kanban-grip @endif>
                    @if ($canShare)
                        <x-edit-in-place id="column-name-{{ $column->id }}" class="kanban-name" name="name" :value="$column->name" :action="route('notes.columns.update', $column->id)" maxlength="60" />
                    @else
                        <h2 id="column-name-{{ $column->id }}" class="kanban-name">{{ $column->name }}</h2>
                    @endif
                    <span class="kanban-count">{{ $cards->count() }}</span>
                    <x-more-menu class="kanban-menu" icon="dots-vertical" :label="__('home.column_menu')">
                        <button type="button" class="menu-item" data-dialog-open="note-new" data-note-column="{{ $column->id }}" data-note-color="{{ $column->newNoteColor() }}" data-kanban-add><x-icon name="plus" /><span>{{ __('home.add_note_menu') }}</span></button>
                        @if ($canShare)
                            <button type="button" class="menu-item" data-focus="column-name-{{ $column->id }}"><x-icon name="pencil" /><span>{{ __('home.column_rename') }}</span></button>
                            <button type="button" class="menu-item" data-dialog-open="column-color-{{ $column->id }}"><x-icon name="palette" /><span>{{ __('home.column_color') }}</span></button>
                            <button type="button" class="menu-item" data-dialog-open="column-note-color-{{ $column->id }}"><x-icon name="note" /><span>{{ __('home.column_note_color') }}</span></button>
                            <hr class="menu-separator">
                            <button type="button" class="menu-item" data-column-move="-1" @disabled($loop->first)><x-icon name="chevron-left" /><span>{{ __('home.column_left') }}</span></button>
                            <button type="button" class="menu-item" data-column-move="1" @disabled($loop->last)><x-icon name="chevron-right" /><span>{{ __('home.column_right') }}</span></button>
                            <hr class="menu-separator">
                            <p class="dropdown-title">{{ __('home.column_sort') }}</p>
                            @foreach (\App\Domain\Notes\NoteColumn::SORTS as $sort)
                                <button type="submit" class="menu-item" form="column-sort-{{ $column->id }}" name="sort" value="{{ $sort }}" role="menuitemradio" aria-checked="{{ $column->note_sort === $sort ? 'true' : 'false' }}"><x-icon name="arrows-sort" /><span>{{ __('home.sort_'.$sort) }}</span></button>
                            @endforeach
                        @endif
                        @if ($cards->isNotEmpty())
                            <hr class="menu-separator">
                            <button type="button" class="menu-item" data-dialog-open="archive-column-{{ $column->id }}"><x-icon name="archive" /><span>{{ __('home.column_archive') }}</span></button>
                        @endif
                        @if ($canShare && $columns->count() > 1)
                            <hr class="menu-separator">
                            <button type="button" class="menu-item menu-danger" data-dialog-open="delete-column-{{ $column->id }}"><x-icon name="trash" /><span>{{ __('home.column_delete') }}</span></button>
                        @endif
                    </x-more-menu>
                </header>

                <ul class="kanban-cards" data-kanban-cards="{{ $column->id }}">
                    @foreach ($cards as $note)
                        @include('home._note', ['note' => $note])
                    @endforeach
                </ul>
            </section>
        @endforeach

        @if ($canShare)
            <form method="post" action="{{ route('notes.columns.store') }}" class="kanban-col kanban-new">
                @csrf
                <label for="new-column">{{ __('home.add_column') }}</label>
                <input id="new-column" class="input-small" name="name" maxlength="60" required placeholder="{{ __('home.column_name') }}">
                <button type="submit"><x-icon name="plus" />{{ __('home.add_column') }}</button>
            </form>
        @endif
    </div>

    @foreach ($columns as $column)
        @if ($canShare)
            <form id="column-sort-{{ $column->id }}" method="post" action="{{ route('notes.columns.look', $column->id) }}" hidden>@csrf</form>
            @foreach (['color' => [$column->color, 'column_color_title', true], 'note_color' => [$column->newNoteColor(), 'column_note_color_title', false]] as $field => [$value, $title, $none])
                <div class="dialog-backdrop" data-dialog="column-{{ str_replace('_', '-', $field) }}-{{ $column->id }}" hidden>
                    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="column-{{ $field }}-{{ $column->id }}-title">
                        <h2 id="column-{{ $field }}-{{ $column->id }}-title">{{ __('home.'.$title) }}</h2>
                        <form method="post" action="{{ route('notes.columns.look', $column->id) }}">
                            @csrf
                            <x-color-picker :name="$field" :value="$value" :allow-none="$none" :key="$field.'-'.$column->id" :legend="$column->name" />
                            <div class="dialog-actions">
                                <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                                <button type="submit">{{ __('home.save') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endforeach
        @endif
        @if ($byColumn->get($column->id, collect())->isNotEmpty())
            <div class="dialog-backdrop" data-dialog="archive-column-{{ $column->id }}" hidden>
                <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="archive-column-{{ $column->id }}-title">
                    <h2 id="archive-column-{{ $column->id }}-title">{{ __('home.column_archive_title', ['name' => $column->name]) }}</h2>
                    <p>{{ __('home.column_archive_hint') }}</p>
                    <div class="dialog-actions">
                        <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                        <form method="post" action="{{ route('notes.columns.archive', $column->id) }}">
                            @csrf
                            <button type="submit">{{ __('home.column_archive') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        @endif
        @if ($canShare && $columns->count() > 1)
            <div class="dialog-backdrop" data-dialog="delete-column-{{ $column->id }}" hidden>
                <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="delete-column-{{ $column->id }}-title">
                    <h2 id="delete-column-{{ $column->id }}-title">{{ __('home.column_delete_title', ['name' => $column->name]) }}</h2>
                    <p>{{ __('home.column_delete_hint') }}</p>
                    <div class="dialog-actions">
                        <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                        <form method="post" action="{{ route('notes.columns.destroy', $column->id) }}">
                            @csrf
                            <button type="submit" class="button-danger">{{ __('home.column_delete') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endforeach

    {{-- Dialogs outside the board, so a note's look and stacking never reach them. --}}
    @foreach ($notes as $note)
        @if ($note->editableBy($userId))
            <div class="dialog-backdrop note-backdrop" data-dialog="edit-note-{{ $note->id }}" data-note-preview="{{ $note->id }}" hidden>
                <div class="dialog note-dialog" role="dialog" aria-modal="true" aria-labelledby="edit-note-{{ $note->id }}-title">
                    <h2 id="edit-note-{{ $note->id }}-title">{{ __('home.edit') }}</h2>
                    <form method="post" action="{{ route('notes.update', $note->id) }}">
                        @csrf
                        <fieldset class="note-fields" data-note-fields @disabled($note->locked)>
                            @include('home._fields', ['note' => $note, 'part' => 'main'])
                        </fieldset>
                        <details class="note-more" data-note-more>
                            <summary>{{ __('home.more_options') }}</summary>
                            <fieldset class="note-fields" data-note-fields @disabled($note->locked)>
                                @include('home._fields', ['note' => $note, 'part' => 'more'])
                            </fieldset>
                            <label class="note-lock-choice"><input type="checkbox" name="locked" value="1" @checked($note->locked) data-note-lock> {{ __('home.locked') }}</label>
                        </details>
                        <div class="dialog-actions">
                            <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                            <button type="submit">{{ __('home.save') }}</button>
                        </div>
                    </form>
                </div>
            </div>
            @unless ($note->locked)
            <div class="dialog-backdrop" data-dialog="delete-note-{{ $note->id }}" hidden>
                <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="delete-note-{{ $note->id }}-title">
                    <h2 id="delete-note-{{ $note->id }}-title">{{ __('home.delete_title') }}</h2>
                    <div class="dialog-actions">
                        <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                        <form method="post" action="{{ route('notes.destroy', $note->id) }}">
                            @csrf
                            <button type="submit" class="button-danger">{{ __('home.delete') }}</button>
                        </form>
                    </div>
                </div>
            </div>
            @endunless
        @endif
    @endforeach
    </x-window>
    </div>
@endsection
