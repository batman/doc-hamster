@extends('layouts.app')

@section('title', __('home.title'))

@section('content')
    <div class="page-head">
        <h1>{{ __('home.title') }}</h1>
        <a class="icon-button" href="{{ route('notes.archive') }}" aria-label="{{ __('home.archive') }}" data-tooltip="{{ __('home.archive') }}"><x-icon name="archive" /></a>
        <button type="button" class="icon-button" data-dialog-open="note-new" data-note-column="{{ $columns->first()?->id }}" aria-label="{{ __('home.new') }}" data-tooltip="{{ __('home.new') }}"><x-icon name="plus" /></button>
    </div>

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
                    @include('home._fields', ['note' => null])
                </fieldset>
                <label class="note-lock-choice"><input type="checkbox" name="locked" value="1"> {{ __('home.locked') }}</label>
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
            <section class="kanban-col" data-column-id="{{ $column->id }}" aria-label="{{ $column->name }}">
                <button type="button" class="col-strip kanban-strip" data-kanban-unfold aria-label="{{ __('home.column_unfold', ['name' => $column->name]) }}" hidden>
                    <x-icon name="chevron-right" />
                    <span class="strip-label"><span>{{ $column->name }}</span><span class="kanban-count">{{ $cards->count() }}</span></span>
                </button>
                <div class="kanban-body">
                    <header class="kanban-head" @if ($canShare) draggable="true" data-kanban-grip @endif>
                        @if ($canShare)
                            <input class="kanban-name" value="{{ $column->name }}" maxlength="60" aria-label="{{ __('home.column_name') }}" data-kanban-rename="{{ route('notes.columns.update', $column->id) }}">
                        @else
                            <h2 class="kanban-name">{{ $column->name }}</h2>
                        @endif
                        <span class="kanban-count">{{ $cards->count() }}</span>
                        <button type="button" class="note-tool" data-kanban-fold aria-label="{{ __('home.column_fold') }}" data-tooltip="{{ __('home.column_fold') }}"><x-icon name="chevron-left" /></button>
                        @if ($canShare && $columns->count() > 1)
                            <button type="button" class="note-tool" data-dialog-open="delete-column-{{ $column->id }}" aria-label="{{ __('home.column_delete') }}" data-tooltip="{{ __('home.column_delete') }}"><x-icon name="trash" /></button>
                        @endif
                    </header>

                    <ul class="kanban-cards" data-kanban-cards="{{ $column->id }}">
                        @foreach ($cards as $note)
                            @include('home._note', ['note' => $note])
                        @endforeach
                    </ul>

                    <button type="button" class="button-quiet kanban-add" data-dialog-open="note-new" data-note-column="{{ $column->id }}"><x-icon name="plus" />{{ __('home.add_note') }}</button>
                </div>
            </section>
        @endforeach

        @if ($canShare)
            <form method="post" action="{{ route('notes.columns.store') }}" class="kanban-col kanban-new">
                @csrf
                <label for="new-column">{{ __('home.add_column') }}</label>
                <input id="new-column" name="name" maxlength="60" required placeholder="{{ __('home.column_name') }}">
                <button type="submit"><x-icon name="plus" />{{ __('home.add_column') }}</button>
            </form>
        @endif
    </div>

    @if ($canShare && $columns->count() > 1)
        @foreach ($columns as $column)
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
        @endforeach
    @endif

    {{-- Dialogs outside the board, so a note's look and stacking never reach them. --}}
    @foreach ($notes as $note)
        @if ($note->editableBy($userId))
            <div class="dialog-backdrop note-backdrop" data-dialog="edit-note-{{ $note->id }}" data-note-preview="{{ $note->id }}" hidden>
                <div class="dialog note-dialog" role="dialog" aria-modal="true" aria-labelledby="edit-note-{{ $note->id }}-title">
                    <h2 id="edit-note-{{ $note->id }}-title">{{ __('home.edit') }}</h2>
                    <form method="post" action="{{ route('notes.update', $note->id) }}">
                        @csrf
                        <fieldset class="note-fields" data-note-fields @disabled($note->locked)>
                            @include('home._fields', ['note' => $note])
                        </fieldset>
                        <label class="note-lock-choice"><input type="checkbox" name="locked" value="1" @checked($note->locked) data-note-lock> {{ __('home.locked') }}</label>
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
@endsection
