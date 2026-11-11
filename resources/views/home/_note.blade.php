@php
    $canEdit = $note->editableBy($userId);
    $open = $canEdit && ! $note->locked;
@endphp
{{-- One note on the board: dragged by its head (app.js), text always a field with a mirror of our own checkboxes. --}}
<li class="note {{ $note->colorClass() }} @if ($note->locked) is-locked @endif" data-note-id="{{ $note->id }}"
    @if ($open) data-note-look="{{ route('notes.look', $note->id) }}" data-note-move="{{ route('notes.move', $note->id) }}" @endif
    @if ($note->height !== null) data-h="{{ $note->height }}" @endif
    @if ($note->opacity !== null) data-opacity="{{ $note->opacity }}" @endif>
    <div class="note-head">
        <span class="note-title">{{ $note->title ?: __('home.untitled') }}</span>
        @if ($canEdit)
            <button type="button" class="note-tool" data-dialog-open="edit-note-{{ $note->id }}" aria-label="{{ __('home.edit') }}" data-tooltip="{{ __('home.edit') }}"><x-icon name="pencil" /></button>
        @endif
        @if ($open)
            <form method="post" action="{{ route('notes.archive_note', $note->id) }}" class="note-tool-form">
                @csrf
                <button type="submit" class="note-tool" aria-label="{{ __('home.archive_note') }}" data-tooltip="{{ __('home.archive_note') }}"><x-icon name="archive" /></button>
            </form>
            <button type="button" class="note-tool" data-dialog-open="delete-note-{{ $note->id }}" aria-label="{{ __('home.delete') }}" data-tooltip="{{ __('home.delete') }}"><x-icon name="x" /></button>
        @endif
    </div>

    <div class="note-content">
        <div class="note-text" @if ($open) data-note-text="{{ route('notes.text', $note->id) }}" @endif>
            <textarea class="note-area" rows="1" spellcheck="false" aria-label="{{ __('home.body') }}" @readonly(! $open)>{{ $note->body }}</textarea>
            <div class="note-mirror" aria-hidden="true"></div>
        </div>

        @if ($note->documents->isNotEmpty())
            <ul class="note-links">
                @foreach ($note->documents as $document)
                    <li><a href="{{ route('documents.show', $document->id) }}"><x-icon name="file-text" />{{ $document->title }}</a></li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="note-meta">
        {{-- Tags as pills in their colour (41); a click shows the receipts with that tag (A21). --}}
        @if ($note->tags->isNotEmpty())
            <ul class="tags note-tags">
                @foreach ($note->tags as $tag)
                    <li><a class="tag tag-{{ $tag->id }}" href="{{ route('documents.index', ['tags' => [$tag->id]]) }}">{{ $tag->name }}</a></li>
                @endforeach
            </ul>
        @endif
        <p>
            @if ($note->locked)<span class="note-lock" title="{{ __('home.locked') }}"><x-icon name="lock" /><span class="visually-hidden">{{ __('home.locked') }}</span></span>@endif
            {{ $note->author->display_name }} · {{ $note->created_at->format('d.m.Y') }}
            @if ($note->shared) · {{ $note->editable ? __('home.shared_editable') : __('home.shared') }} @endif
        </p>
    </div>
</li>
