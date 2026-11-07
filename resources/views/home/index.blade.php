@extends('layouts.app')

@section('title', __('home.title'))

@section('content')
    <div class="page-head">
        <h1>{{ __('home.title') }}</h1>
        <button type="button" class="icon-button" data-dialog-open="note-new" aria-label="{{ __('home.new') }}" data-tooltip="{{ __('home.new') }}"><x-icon name="plus" /></button>
    </div>

    @if ($connectPhone)
        <p class="notice"><x-icon name="device-mobile" /> {{ __('phone.home_hint') }} <a href="{{ route('phone.index') }}">{{ __('phone.start') }}</a></p>
    @endif

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
                    <span class="note-title">{{ $note->title ?: __('home.untitled') }}</span>
                    @if ($canEdit)
                        <button type="button" class="note-tool" data-dialog-open="edit-note-{{ $note->id }}" aria-label="{{ __('home.edit') }}" data-tooltip="{{ __('home.edit') }}"><x-icon name="pencil" /></button>
                    @endif
                    @if ($open)
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
                    @if ($note->locked)<span class="note-lock" title="{{ __('home.locked') }}"><x-icon name="lock" /><span class="visually-hidden">{{ __('home.locked') }}</span></span>@endif
                    {{ $note->author->display_name }} · {{ $note->created_at->format('d.m.Y') }}
                    @if ($note->shared) · {{ $note->editable ? __('home.shared_editable') : __('home.shared') }} @endif
                </p>
            </li>
        @endforeach
    </ul>

    {{-- Dialogs outside the board, so a note's look and stacking never reach them. --}}
    @foreach ($notes as $note)
        @if ($note->editableBy($userId))
            <div class="dialog-backdrop" data-dialog="edit-note-{{ $note->id }}" hidden>
                <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="edit-note-{{ $note->id }}-title">
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
