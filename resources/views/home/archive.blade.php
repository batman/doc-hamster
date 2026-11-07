@extends('layouts.app')

@section('title', __('home.archive'))

@section('content')
    <div class="page-head">
        <h1>{{ __('home.archive') }}</h1>
        <a class="icon-button" href="{{ route('home') }}" aria-label="{{ __('home.back_to_board') }}" data-tooltip="{{ __('home.back_to_board') }}"><x-icon name="chevron-left" /></a>
    </div>

    {{-- Archived notes (A41): back to the board, or deleted for good. --}}
    @if ($notes->isEmpty())
        <p class="muted">{{ __('home.archive_empty') }}</p>
    @else
        <ul class="documents note-archive">
            @foreach ($notes as $note)
                <li>
                    <span class="note-archive-name">
                        <strong>{{ $note->title ?: __('home.untitled') }}</strong>
                        <span class="muted">{{ \Illuminate\Support\Str::limit((string) $note->body, 80) }}</span>
                    </span>
                    @if ($note->editableBy($userId))
                        <form method="post" action="{{ route('notes.restore', $note->id) }}">
                            @csrf
                            <button type="submit" class="icon-button" aria-label="{{ __('home.restore') }}" data-tooltip="{{ __('home.restore') }}"><x-icon name="arrow-back-up" /></button>
                        </form>
                        @unless ($note->locked)
                            <button type="button" class="icon-button" data-dialog-open="delete-note-{{ $note->id }}" aria-label="{{ __('home.delete') }}" data-tooltip="{{ __('home.delete') }}"><x-icon name="trash" /></button>
                        @endunless
                    @endif
                </li>
            @endforeach
        </ul>

        @foreach ($notes as $note)
            @if ($note->editableBy($userId) && ! $note->locked)
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
    @endif
@endsection
