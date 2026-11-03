@extends('layouts.app')

@section('title', __('home.title'))

@section('content')
    <div class="page-head">
        <h1>{{ __('home.title') }}</h1>
    </div>

    <datalist id="note-documents">
        @foreach ($documents as $document)
            <option value="{{ $document->title }}"></option>
        @endforeach
    </datalist>

    <ul class="notes">
        <li class="note note-new">
            <details>
                <summary>{{ __('home.new') }}</summary>
                <form method="post" action="{{ route('notes.store') }}">
                    @csrf
                    @include('home._fields', ['note' => null])
                    <button type="submit">{{ __('home.save') }}</button>
                </form>
            </details>
        </li>

        @foreach ($notes as $note)
            @php($canEdit = $note->editableBy($userId))
            <li class="note">
                @if ($note->body)
                    <p class="note-body">{{ $note->body }}</p>
                @endif

                @if ($note->checklist)
                    <form method="post" action="{{ route('notes.check', $note->id) }}" data-autosubmit>
                        @csrf
                        <ul class="note-checklist">
                            @foreach ($note->checklist as $i => $item)
                                <li><label><input type="checkbox" name="done[]" value="{{ $i }}" @checked($item['done']) @disabled(! $canEdit)> <span>{{ $item['text'] }}</span></label></li>
                            @endforeach
                        </ul>
                        @if ($canEdit)
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

                <p class="note-meta">
                    {{ $note->author->display_name }} · {{ $note->created_at->format('d.m.Y') }}
                    @if ($note->shared) · {{ $note->editable ? __('home.shared_editable') : __('home.shared') }} @endif
                </p>

                @if ($canEdit)
                    <details class="note-edit">
                        <summary>{{ __('home.edit') }}</summary>
                        <form method="post" action="{{ route('notes.update', $note->id) }}">
                            @csrf
                            @include('home._fields', ['note' => $note])
                            <button type="submit">{{ __('home.save') }}</button>
                        </form>
                        <button type="button" class="button-danger" data-dialog-open="delete-note-{{ $note->id }}">{{ __('home.delete') }}</button>
                    </details>
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
            </li>
        @endforeach
    </ul>
@endsection
