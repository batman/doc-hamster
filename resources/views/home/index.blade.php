@extends('layouts.app')

@section('title', __('home.title'))

@section('content')
    <div class="page-head">
        <h1>{{ __('home.title') }}</h1>
        <button type="button" class="icon-button" data-dialog-open="note-new" aria-label="{{ __('home.new') }}" data-tooltip="{{ __('home.new') }}"><x-icon name="plus" /></button>
    </div>

    <datalist id="note-documents">
        @foreach ($documents as $document)
            <option value="{{ $document->title }}"></option>
        @endforeach
    </datalist>

    <div class="dialog-backdrop" data-dialog="note-new" hidden>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="note-new-title">
            <h2 id="note-new-title">{{ __('home.new') }}</h2>
            <form method="post" action="{{ route('notes.store') }}">
                @csrf
                @include('home._fields', ['note' => null])
                <div class="dialog-actions">
                    <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                    <button type="submit">{{ __('home.save') }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- A board: on wide screens app.js places the notes freely and they can be dragged by their head; otherwise a grid. --}}
    <ul class="notes" data-notes data-csrf="{{ csrf_token() }}">
        @foreach ($notes as $note)
            @php
                $canEdit = $note->editableBy($userId);
                $open = $canEdit && ! $note->locked;
                $color = $note->color ?? 'yellow';
            @endphp
            <li class="note note-{{ $color }} @if ($note->locked) is-locked @endif"
                @if ($open) data-note-look="{{ route('notes.look', $note->id) }}" @endif
                @if ($note->pos_x !== null && $note->pos_y !== null) data-x="{{ $note->pos_x }}" data-y="{{ $note->pos_y }}" @endif
                @if ($note->opacity !== null) data-opacity="{{ $note->opacity }}" @endif>
                <div class="note-head">
                    @if ($canEdit)
                        <form method="post" action="{{ route('notes.look', $note->id) }}">
                            @csrf
                            <input type="hidden" name="locked" value="{{ $note->locked ? 0 : 1 }}">
                            <button type="submit" class="note-tool" aria-label="{{ $note->locked ? __('home.unlock') : __('home.lock') }}" data-tooltip="{{ $note->locked ? __('home.unlock') : __('home.lock') }}"><x-icon :name="$note->locked ? 'lock' : 'lock-open'" /></button>
                        </form>
                    @elseif ($note->locked)
                        <span class="note-tool" aria-label="{{ __('home.locked') }}"><x-icon name="lock" /></span>
                    @endif
                    <span class="note-title">{{ $note->title ?: __('home.untitled') }}</span>
                    @if ($open)
                        <div class="dropdown note-menu">
                            <button type="button" class="icon-button note-tool" aria-expanded="false" aria-label="{{ __('home.menu') }}"><x-icon name="chevron-down" /></button>
                            <div class="dropdown-panel" hidden>
                                <button type="button" class="menu-item" data-dialog-open="edit-note-{{ $note->id }}"><x-icon name="pencil" />{{ __('home.edit') }}</button>
                                <p class="dropdown-title">{{ __('home.color') }}</p>
                                <form method="post" action="{{ route('notes.look', $note->id) }}" class="note-colors">
                                    @csrf
                                    @foreach (\App\Domain\Notes\Note::COLORS as $choice)
                                        <button type="submit" name="color" value="{{ $choice }}" class="note-swatch note-{{ $choice }}" aria-label="{{ __('home.colors.'.$choice) }}" aria-pressed="{{ $choice === $color ? 'true' : 'false' }}"></button>
                                    @endforeach
                                </form>
                                <form method="post" action="{{ route('notes.look', $note->id) }}" class="note-opacity" data-autosubmit>
                                    @csrf
                                    <label for="opacity-{{ $note->id }}" class="dropdown-title">{{ __('home.opacity') }}</label>
                                    <input id="opacity-{{ $note->id }}" type="range" name="opacity" min="30" max="100" step="5" value="{{ $note->opacity ?? 100 }}" data-note-opacity>
                                    <noscript><button type="submit">{{ __('home.save') }}</button></noscript>
                                </form>
                                <button type="button" class="menu-item" data-dialog-open="delete-note-{{ $note->id }}"><x-icon name="trash" />{{ __('home.delete') }}</button>
                            </div>
                        </div>
                        <button type="button" class="note-tool" data-dialog-open="delete-note-{{ $note->id }}" aria-label="{{ __('home.delete') }}" data-tooltip="{{ __('home.delete') }}"><x-icon name="x" /></button>
                    @endif
                </div>

                <div class="note-content">
                    @if ($note->body)
                        <p class="note-body">{{ $note->body }}</p>
                    @endif

                    @if ($note->checklist)
                        <form method="post" action="{{ route('notes.check', $note->id) }}" data-autosubmit>
                            @csrf
                            <ul class="note-checklist">
                                @foreach ($note->checklist as $i => $item)
                                    <li><label><input type="checkbox" name="done[]" value="{{ $i }}" @checked($item['done']) @disabled(! $open)> <span>{{ $item['text'] }}</span></label></li>
                                @endforeach
                            </ul>
                            @if ($open)
                                <noscript><button type="submit">{{ __('home.save') }}</button></noscript>
                            @endif
                        </form>
                    @endif

                    @if ($note->documents->isNotEmpty())
                        <ul class="note-links">
                            @foreach ($note->documents as $document)
                                <li><a href="{{ route('documents.show', $document->id) }}"><x-icon name="file-text" />{{ $document->title }}</a></li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <p class="note-meta">
                    {{ $note->author->display_name }} · {{ $note->created_at->format('d.m.Y') }}
                    @if ($note->shared) · {{ $note->editable ? __('home.shared_editable') : __('home.shared') }} @endif
                </p>
            </li>
        @endforeach
    </ul>

    {{-- Dialogs outside the board, so a note's look and stacking never reach them. --}}
    @foreach ($notes as $note)
        @if ($note->editableBy($userId) && ! $note->locked)
            <div class="dialog-backdrop" data-dialog="edit-note-{{ $note->id }}" hidden>
                <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="edit-note-{{ $note->id }}-title">
                    <h2 id="edit-note-{{ $note->id }}-title">{{ __('home.edit') }}</h2>
                    <form method="post" action="{{ route('notes.update', $note->id) }}">
                        @csrf
                        @include('home._fields', ['note' => $note])
                        <div class="dialog-actions">
                            <button type="button" class="button-quiet" data-dialog-close>{{ __('documents.cancel') }}</button>
                            <button type="submit">{{ __('home.save') }}</button>
                        </div>
                    </form>
                </div>
            </div>
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
        @endif
    @endforeach
@endsection
